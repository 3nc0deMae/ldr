"""
LDB-FRAS - Python Face Recognition Engine
Flask-based API for face detection, encoding, and recognition
Uses DeepFace + Facenet512 (no C++ compilation required)
"""

import os
import cv2
import numpy as np
import base64
import json
import logging
import uuid
import warnings
from datetime import datetime

# Suppress TensorFlow info messages and enable GPU memory growth by default
os.environ['TF_CPP_MIN_LOG_LEVEL'] = '2'
os.environ['TF_FORCE_GPU_ALLOW_GROWTH'] = 'true'
os.environ['TF_GPU_ALLOCATOR'] = 'cuda_malloc_async'
warnings.filterwarnings('ignore')

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

# Hardware acceleration: configure GPU if available before importing TensorFlow/DeepFace
try:
    import tensorflow as tf
    gpus = tf.config.experimental.list_physical_devices('GPU')
    if gpus:
        for gpu in gpus:
            tf.config.experimental.set_memory_growth(gpu, True)
        logger.info(f"Hardware acceleration enabled: {len(gpus)} GPU(s) detected")
    else:
        logger.info("No GPU detected - running on CPU")
except Exception as e:
    logger.warning(f"GPU configuration skipped (using CPU fallback): {e}")

# Configuration
UPLOAD_FOLDER = 'uploads/faces'
ALLOWED_EXTENSIONS = {'png', 'jpg', 'jpeg'}
API_KEY = os.environ.get('PYTHON_API_KEY', 'ldb_fras_api_key_2026')

# Model & detector settings
# Detector backend can be overridden via environment variable:
#   FACE_DETECTOR=mediapipe   (lightweight, CPU-friendly)
#   FACE_DETECTOR=yunet       (lightweight, CPU-friendly)
#   FACE_DETECTOR=retinaface  (default, high accuracy)
SUPPORTED_DETECTORS = {'retinaface', 'yunet', 'mediapipe', 'opencv', 'ssd', 'dlib', 'mtcnn'}
_raw_detector = os.environ.get('FACE_DETECTOR', 'yunet').strip().lower()
if _raw_detector not in SUPPORTED_DETECTORS:
    logger.warning(f"Unsupported detector '{_raw_detector}', falling back to 'yunet'")
    DETECTOR_BACKEND = 'yunet'
else:
    DETECTOR_BACKEND = _raw_detector

MODEL_NAME = 'Facenet512'                            # 512-d embeddings, high accuracy
TOLERANCE = 0.45                                     # Cosine distance threshold (lower = stricter)
MIN_MATCH_MARGIN = 0.10                              # minimum gap between best and 2nd-best distance
MIN_CONFIDENCE = 50.0                                # minimum confidence (%) to accept a match

# Image quality thresholds
BRIGHTNESS_MIN = 20                                  # minimum average brightness (0-255)
BRIGHTNESS_MAX = 240                                 # maximum average brightness (overexposure)
CONTRAST_MIN = 10                                    # minimum contrast (std dev of pixels)
FACE_SIZE_MIN = 30                                   # minimum face dimension in pixels

# Face detection zone constraint
FACE_ZONE_MARGIN = 0.05                              # 5% inset from each edge

# Performance: Resize long-edge before detection for faster inference.
# 1280px preserves facial keypoint quality required by Facenet512.
MAX_DETECTION_SIZE = 1280

os.makedirs(UPLOAD_FOLDER, exist_ok=True)

# Import DeepFace (imports TensorFlow on first use — takes ~10-20 seconds)
logger.info("Loading DeepFace + Facenet512 model (first run downloads ~90MB model)...")
from deepface import DeepFace
from flask import Flask, request, jsonify
from flask_cors import CORS

# Global model cache to prevent reloading
_model_cache = {}

def get_model(model_name=None):
    """
    Get or build model with caching to prevent reloads.
    This ensures the model stays in memory between requests.
    """
    if model_name is None:
        model_name = MODEL_NAME
    
    if model_name not in _model_cache:
        logger.info(f"Building {model_name} model...")
        try:
            _model_cache[model_name] = DeepFace.build_model(model_name)
            logger.info(f"{model_name} model loaded successfully.")
        except Exception as e:
            logger.error(f"Failed to load {model_name}: {e}")
            raise
    
    return _model_cache[model_name]

# ============================================================
# MODEL WARM-UP
# ============================================================

def warmup_models():
    """
    Warm up models by running dummy inference on a blank image.
    This pre-loads the detector and encoder into memory,
    eliminating cold-start latency on the first real request.
    Returns True if warm-up succeeded, False otherwise.
    """
    logger.info("Warming up models (dummy inference)...")
    try:
        dummy_img = np.zeros((300, 300, 3), dtype=np.uint8)
        
        results = DeepFace.represent(
            img_path=dummy_img,
            model_name=MODEL_NAME,
            detector_backend=DETECTOR_BACKEND,
            enforce_detection=False,
        )
        logger.info(f"Model warm-up complete ({DETECTOR_BACKEND} + {MODEL_NAME})")
        return True
    except Exception as e:
        logger.warning(f"Model warm-up failed (will retry on first request): {e}")
        return False

# Pre-load model on startup for faster first request
try:
    get_model()
except Exception as e:
    logger.warning(f"Model pre-load warning (may resolve on first request): {e}")

app = Flask(__name__)
CORS(app)

# ============================================================
# HELPER FUNCTIONS
# ============================================================

def allowed_file(filename):
    """Check if file extension is allowed"""
    return '.' in filename and filename.rsplit('.', 1)[1].lower() in ALLOWED_EXTENSIONS


_haar_cascade = None

def get_haar_cascade():
    """Lazy-load Haar cascade for fast face size checking without DeepFace."""
    global _haar_cascade
    if _haar_cascade is None:
        cascade_path = cv2.data.haarcascades + 'haarcascade_frontalface_default.xml'
        _haar_cascade = cv2.CascadeClassifier(cascade_path)
    return _haar_cascade


def is_face_in_zone(face_x, face_y, face_w, face_h, img_h, img_w, margin=FACE_ZONE_MARGIN):
    """
    Check whether the center of a detected face falls within the central
    detection zone of the image.  Faces outside the zone (e.g. at the sides
    of the frame) are rejected so only well-positioned frontal faces are
    accepted.

    The zone is defined by `margin` inset from each edge:
        zone_left   = img_w * margin
        zone_right  = img_w * (1 - margin)
        zone_top    = img_h * margin
        zone_bottom = img_h * (1 - margin)

    Returns True if the face center is inside the zone, False otherwise.
    """
    if img_w <= 0 or img_h <= 0:
        return False

    cx = face_x + face_w / 2.0
    cy = face_y + face_h / 2.0

    zone_left   = img_w * margin
    zone_right  = img_w * (1.0 - margin)
    zone_top    = img_h * margin
    zone_bottom = img_h * (1.0 - margin)

    return (zone_left <= cx <= zone_right) and (zone_top <= cy <= zone_bottom)


def resize_for_detection(image):
    """
    Resize image so the longest edge is at most MAX_DETECTION_SIZE,
    preserving aspect ratio. Returns (resized_image, scale_factor).
    RetinaFace is robust enough to work accurately on resized images.
    """
    h, w = image.shape[:2]
    longest = max(h, w)
    if longest <= MAX_DETECTION_SIZE:
        return image, 1.0

    scale = MAX_DETECTION_SIZE / float(longest)
    new_w = int(w * scale)
    new_h = int(h * scale)

    resized = cv2.resize(image, (new_w, new_h), interpolation=cv2.INTER_AREA)
    return resized, scale


def select_primary_face(detections, img_shape):
    """
    From a list of DeepFace detection results, select the single best face
    for recognition that is also within the central detection zone.
    Priority:
      1. Largest face area (closest to camera) within the zone.
      2. Closest to frame center (tie-breaker) within the zone.

    Faces outside the detection zone are rejected so that only well-
    positioned frontal faces are accepted.

    Returns the selected detection dict, or None if the list is empty
    or no face falls within the zone.
    """
    if not detections:
        return None

    img_h, img_w = img_shape[:2]
    center_x, center_y = img_w / 2.0, img_h / 2.0

    def _score(det):
        region = det.get('facial_area', {})
        x = int(region.get('x', 0))
        y = int(region.get('y', 0))
        w = int(region.get('w', 0))
        h = int(region.get('h', 0))
        area = w * h
        cx = x + w / 2.0
        cy = y + h / 2.0
        dist_to_center = ((cx - center_x) ** 2 + (cy - center_y) ** 2) ** 0.5
        return (area, -dist_to_center)

    in_zone = [
        det for det in detections
        if is_face_in_zone(
            int(det.get('facial_area', {}).get('x', 0)),
            int(det.get('facial_area', {}).get('y', 0)),
            int(det.get('facial_area', {}).get('w', 0)),
            int(det.get('facial_area', {}).get('h', 0)),
            img_h, img_w
        )
    ]

    if not in_zone:
        return None

    return max(in_zone, key=_score)


def decode_base64_image(base64_string):
    """Decode base64 string to image array (BGR, OpenCV format)"""
    if ',' in base64_string:
        base64_string = base64_string.split(',')[1]

    img_data = base64.b64decode(base64_string)
    np_array = np.frombuffer(img_data, np.uint8)
    image = cv2.imdecode(np_array, cv2.IMREAD_COLOR)
    return image


def save_temp_image(image):
    """Save image to a unique temporary file and return the path.
    DeepFace's represent() requires a file path.
    A unique name avoids concurrent requests overwriting each other's temp file."""
    temp_path = os.path.join(UPLOAD_FOLDER, f'_temp_{uuid.uuid4().hex}.jpg')
    cv2.imwrite(temp_path, image)
    return temp_path


def detect_and_crop_face(image, padding_factor=0.15):
    """
    Detect the primary face and crop the image tightly around it, minimizing
    hair, headwear, and background influence. Uses a smaller upward padding
    so the detector focuses on the facial area rather than hair volume.
    Returns the cropped image (numpy array), or None if no face detected.
    """
    temp_path = None
    try:
        # Resize to speed up detection while keeping accuracy
        resized, scale = resize_for_detection(image)

        temp_path = save_temp_image(resized)

        detections = DeepFace.represent(
            img_path=temp_path,
            model_name=MODEL_NAME,
            detector_backend=DETECTOR_BACKEND,
            enforce_detection=False,
        )

        if not isinstance(detections, list) or len(detections) == 0:
            return None

        # Detection coordinates are relative to the RESIZED image, so the
        # zone check must run against the resized dimensions too.
        resized_h, resized_w = resized.shape[:2]
        best = select_primary_face(detections, resized.shape)
        if best is None:
            return None

        region = best.get('facial_area', {})
        if not region:
            return None

        if not is_face_in_zone(
            int(region.get('x', 0)),
            int(region.get('y', 0)),
            int(region.get('w', 0)),
            int(region.get('h', 0)),
            resized_h, resized_w
        ):
            return None

        x = int(region.get('x', 0))
        y = int(region.get('y', 0))
        w = int(region.get('w', 0))
        h = int(region.get('h', 0))

        if w <= 0 or h <= 0:
            return None

        # Scale detection back to original image coordinates
        if scale != 1.0:
            x = int(x / scale)
            y = int(y / scale)
            w = int(w / scale)
            h = int(h / scale)

        img_h, img_w = image.shape[:2]

        pad_w = int(w * padding_factor)
        pad_h_top = int(h * padding_factor * 0.5)
        pad_h_bottom = int(h * padding_factor * 1.2)

        x1 = max(0, x - pad_w)
        y1 = max(0, y - pad_h_top)
        x2 = min(img_w, x + w + pad_w)
        y2 = min(img_h, y + h + pad_h_bottom)

        cropped = image[y1:y2, x1:x2]
        if cropped.size == 0:
            return None

        return cropped
    except Exception:
        return None
    finally:
        if temp_path and os.path.exists(temp_path):
            try:
                os.remove(temp_path)
            except OSError:
                pass


def check_image_quality(image, check_face_size=True):
    """
    Check image quality: brightness, contrast, and optionally face size.
    Blur is intentionally NOT checked — the system must still detect faces
    even if they are slightly blurry, because unique facial features can
    still be extracted for matching (including for identical twins).
    Face size is checked via Haar cascade (fast) to avoid a second DeepFace call.
    Returns (is_ok: bool, issues: list[str], metrics: dict).
    """
    issues = []
    metrics = {}

    try:
        gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    except Exception:
        return False, ['Could not process image'], metrics

    # Brightness
    mean_brightness = float(np.mean(gray))
    metrics['brightness'] = round(mean_brightness, 2)
    if mean_brightness < BRIGHTNESS_MIN:
        issues.append(f'Image too dark (brightness: {round(mean_brightness, 1)}). Please improve lighting.')
    elif mean_brightness > BRIGHTNESS_MAX:
        issues.append(f'Image too bright (brightness: {round(mean_brightness, 1)}). Reduce lighting or avoid direct sunlight.')

    # Contrast
    contrast = float(np.std(gray))
    metrics['contrast'] = round(contrast, 2)
    if contrast < CONTRAST_MIN:
        issues.append(f'Low contrast ({round(contrast, 1)}). Face may be washed out.')

    # NOTE: Blur detection intentionally removed. A blurry face should still
    # be processed and matched. The Facenet512 embedding can extract unique
    # features even from blurry images. Only poor lighting should be flagged.

    # Face size check via Haar cascade (fast, lightweight)
    if check_face_size:
        try:
            cascade = get_haar_cascade()
            h_img, w_img = gray.shape
            min_size = (FACE_SIZE_MIN, FACE_SIZE_MIN)
            detected = cascade.detectMultiScale(gray, scaleFactor=1.1, minNeighbors=3, minSize=min_size)
            if isinstance(detected, np.ndarray) and len(detected) > 0:
                x, y, w, h = detected[0]
                metrics['face_width'] = int(w)
                metrics['face_height'] = int(h)
        except Exception:
            pass

    is_ok = len(issues) == 0
    return is_ok, issues, metrics


def encode_face(image, check_quality=True, crop=True):
    """
    Detect and encode face from image using DeepFace + Facenet512.
    Returns (face_encoding: list, error: str|None).
    Encoding is a 512-dimensional embedding vector.

    crop=True (default, for registration): detect → crop face region → encode crop.
    This makes stored embeddings more robust to hairstyle and background changes.

    crop=False (for live recognition): encode directly in one pass.
    Faster because it skips crop. The stored encodings were created with crop=True,
    so they remain hairstyle-robust even when live scans are encoded without crop.
    """
    temp_path = None
    try:
        if check_quality:
            quality_ok, quality_issues, _ = check_image_quality(image)
            if not quality_ok:
                return None, '; '.join(quality_issues)

        if crop:
            cropped = detect_and_crop_face(image)
            if cropped is None or cropped.size == 0:
                return None, "Could not isolate face region. Please ensure face is clearly visible."
            encode_target = cropped
        else:
            encode_target = image

        temp_path = save_temp_image(encode_target)

        result = DeepFace.represent(
            img_path=temp_path,
            model_name=MODEL_NAME,
            detector_backend=DETECTOR_BACKEND,
            enforce_detection=False,
        )

        if not isinstance(result, list) or len(result) == 0:
            return None, "No face detected in the image"

        if len(result) > 1:
            best = select_primary_face(result, encode_target.shape)
            if best is None:
                return None, "Face is outside the detection zone. Please position your face in the center of the frame."
            embedding = best.get('embedding')
            if embedding is not None:
                return embedding, None

        embedding = result[0].get('embedding')
        if embedding is None:
            return None, "Could not encode face"
        
        if len(result) == 1:
            single = result[0]
            region = single.get('facial_area', {})
            if region:
                fx = int(region.get('x', 0))
                fy = int(region.get('y', 0))
                fw = int(region.get('w', 0))
                fh = int(region.get('h', 0))
                if not is_face_in_zone(fx, fy, fw, fh, encode_target.shape[0], encode_target.shape[1]):
                    return None, "Face is outside the detection zone. Please position your face in the center of the frame."
        
        return embedding, None

    except Exception as e:
        error_msg = str(e)
        if 'Face could not be detected' in error_msg or 'no face' in error_msg.lower():
            return None, "No face detected in the image"
        logger.error(f"Face encoding error: {error_msg}")
        return None, f"Error encoding face: {error_msg}"
    finally:
        if temp_path and os.path.exists(temp_path):
            try:
                os.remove(temp_path)
            except OSError:
                pass


def compare_faces(known_encoding, unknown_encoding, tolerance=TOLERANCE):
    """
    Compare two face encodings using cosine distance.
    Returns (match: bool, distance: float).
    Distance < tolerance means a match.
    """
    try:
        known = np.array(known_encoding, dtype=np.float32)
        unknown = np.array(unknown_encoding, dtype=np.float32)

        # Cosine distance = 1 - cosine_similarity
        dot_product = float(np.dot(known, unknown))
        norm_known = float(np.linalg.norm(known))
        norm_unknown = float(np.linalg.norm(unknown))

        if norm_known == 0 or norm_unknown == 0:
            return False, 1.0

        similarity = dot_product / (norm_known * norm_unknown)
        distance = 1.0 - similarity

        return distance < tolerance, distance

    except Exception as e:
        logger.error(f"Face comparison error: {str(e)}")
        return False, 1.0


def detect_faces_in_image(image):
    """
    Detect faces in an image using DeepFace.
    Returns list of face bounding boxes.
    """
    temp_path = None
    try:
        resized, scale = resize_for_detection(image)
        temp_path = save_temp_image(resized)

        result = DeepFace.represent(
            img_path=temp_path,
            model_name=MODEL_NAME,
            detector_backend=DETECTOR_BACKEND,
            enforce_detection=False,
        )

        faces = []
        if isinstance(result, list):
            # Detection coordinates are relative to the RESIZED image, so the
            # zone check must run against the resized dimensions too.
            resized_h, resized_w = resized.shape[:2]
            for face_data in result:
                region = face_data.get('facial_area', {})
                if region:
                    x = int(region.get('x', 0))
                    y = int(region.get('y', 0))
                    w = int(region.get('w', 0))
                    h = int(region.get('h', 0))
                    if not is_face_in_zone(x, y, w, h, resized_h, resized_w):
                        continue
                    if scale != 1.0:
                        x = int(x / scale)
                        y = int(y / scale)
                        w = int(w / scale)
                        h = int(h / scale)
                    faces.append({
                        'top': int(y),
                        'right': int(x + w),
                        'bottom': int(y + h),
                        'left': int(x)
                    })

        return faces

    except Exception as e:
        logger.error(f"Face detection error: {str(e)}")
        return []
    finally:
        if temp_path and os.path.exists(temp_path):
            try:
                os.remove(temp_path)
            except OSError:
                pass


# ============================================================
# API ENDPOINTS
# ============================================================

@app.route('/api/health', methods=['GET'])
def health_check():
    """Health check endpoint"""
    return jsonify({
        'status': 'ok',
        'service': 'LDB-FRAS Face Recognition Engine',
        'engine': f'DeepFace ({MODEL_NAME})',
        'timestamp': datetime.now().isoformat()
    })


@app.route('/api/check-quality', methods=['POST'])
def check_quality_endpoint():
    """
    Check face image quality without encoding.
    Request: { image: base64_string }
    Response: { quality_ok: bool, issues: [...], metrics: {...} }
    """
    try:
        data = request.get_json()
        if not data or 'image' not in data:
            return jsonify({'error': 'No image provided'}), 400

        image = decode_base64_image(data['image'])
        if image is None:
            return jsonify({'error': 'Invalid image data'}), 400

        quality_ok, issues, metrics = check_image_quality(image)

        return jsonify({
            'quality_ok': quality_ok,
            'issues': issues,
            'metrics': metrics,
            'status': 'good' if quality_ok else 'poor'
        })

    except Exception as e:
        logger.error(f"Quality check error: {str(e)}")
        return jsonify({'error': str(e)}), 500


@app.route('/api/encode-face', methods=['POST'])
def encode_face_endpoint():
    """
    Encode a face from uploaded image
    Request: { image: base64_string }
    Response: { encoding: [...], status: 'success' }
    """
    try:
        data = request.get_json()

        if not data or 'image' not in data:
            return jsonify({'error': 'No image provided'}), 400

        image = decode_base64_image(data['image'])
        if image is None:
            return jsonify({'error': 'Invalid image data'}), 400

        encoding, error = encode_face(image)

        if error:
            return jsonify({'error': error}), 400

        return jsonify({
            'status': 'success',
            'encoding': encoding,
            'message': 'Face encoded successfully'
        })

    except Exception as e:
        logger.error(f"Encode face error: {str(e)}")
        return jsonify({'error': str(e)}), 500


@app.route('/api/recognize-face', methods=['POST'])
def recognize_face_endpoint():
    """
    Recognize a face against known student encodings
    Request: { image: base64_string, known_faces: [{student_id, encoding}] }
    Response: { matched: bool, student_id: string, confidence: float, quality_issues: [...] }
    """
    try:
        data = request.get_json()

        if not data or 'image' not in data:
            return jsonify({'error': 'No image provided'}), 400

        if 'known_faces' not in data or not data['known_faces']:
            return jsonify({'error': 'No known faces provided'}), 400

        image = decode_base64_image(data['image'])
        if image is None:
            return jsonify({'error': 'Invalid image data'}), 400

        # Quick quality check on the incoming scan image
        quality_ok, quality_issues, _ = check_image_quality(image, check_face_size=False)
        quality_warnings = quality_issues if not quality_ok else []

        # Do NOT reject blurry frames — process them anyway so identical twins
        # can still be distinguished. Only reject severely bad lighting.
        severe_issues = [i for i in quality_issues if 'too dark' in i.lower() or 'too bright' in i.lower()]
        if severe_issues:
            return jsonify({
                'matched': False,
                'student_id': None,
                'confidence': 0,
                'quality_warnings': quality_warnings,
                'message': 'Image quality insufficient: ' + '; '.join(severe_issues)
            })

        # Live recognition must encode the SAME isolated face region that was
        # used when the student's embedding was registered. Registration bakes
        # embeddings with crop=True (tight face crop, hairstyle/background
        # robust). If live scans are encoded with crop=False (whole frame), the
        # embedding is computed from a very different image region and the
        # cosine distance to the stored embedding becomes unreliable.
        unknown_encoding, error = encode_face(image, check_quality=False, crop=True)
        if error:
            return jsonify({'error': error, 'matched': False, 'quality_warnings': quality_warnings}), 400

        # Compare the unknown face against EVERY registered student and rank
        # the candidates by cosine distance (closest first).
        ranked = []
        for face_data in data['known_faces']:
            known_encoding = json.loads(face_data['encoding']) if isinstance(
                face_data['encoding'], str
            ) else face_data['encoding']

            is_match, distance = compare_faces(known_encoding, unknown_encoding)
            ranked.append({
                'student_id': face_data['student_id'],
                'distance': distance,
                'is_match': is_match,
            })

        ranked.sort(key=lambda r: r['distance'])
        best = ranked[0] if ranked else None
        second = ranked[1] if len(ranked) > 1 else None

        best_distance = float(best['distance']) if best else 1.0
        second_distance = float(second['distance']) if second else 1.0
        margin = second_distance - best_distance          # > 0 when unambiguous
        confidence = round((1 - best_distance) * 100, 2)

        # A genuine match needs a confident, unambiguous result. An unregistered
        # face that happens to be closest to some random student must be rejected.
        # Stricter thresholds help distinguish identical twins: the best match
        # must be clearly the closest match with high confidence.
        matched = bool(best and best['is_match'])
        ambiguous = bool(second is not None and margin < MIN_MATCH_MARGIN)

        if matched and not ambiguous and confidence >= MIN_CONFIDENCE:
            return jsonify({
                'matched': True,
                'student_id': best['student_id'],
                'confidence': confidence,
                'distance': best_distance,
                'second_distance': second_distance,
                'margin': margin,
                'quality_warnings': quality_warnings,
                'message': f"Match found with {confidence}% confidence"
            })

        # Rejected — do NOT identify as any student.
        if ambiguous:
            reason = 'Face is ambiguous — could not be matched confidently. Please stand closer to the camera.'
        elif best and not best['is_match']:
            reason = 'Face does not match any registered student'
        else:
            reason = 'No matching face found'

        return jsonify({
            'matched': False,
            'student_id': None,
            'confidence': confidence,
            'distance': best_distance,
            'second_distance': second_distance,
            'margin': margin,
            'quality_warnings': quality_warnings,
            'message': reason
        })

    except Exception as e:
        logger.error(f"Recognize face error: {str(e)}")
        return jsonify({'error': str(e), 'matched': False}), 500


@app.route('/api/detect-face', methods=['POST'])
def detect_face_endpoint():
    """
    Detect faces in an image and return face locations
    Request: { image: base64_string }
    Response: { faces: [{top, right, bottom, left}], count: int }
    """
    try:
        data = request.get_json()

        if not data or 'image' not in data:
            return jsonify({'error': 'No image provided'}), 400

        image = decode_base64_image(data['image'])
        if image is None:
            return jsonify({'error': 'Invalid image data'}), 400

        faces = detect_faces_in_image(image)

        return jsonify({
            'faces': faces,
            'count': len(faces)
        })

    except Exception as e:
        logger.error(f"Detect face error: {str(e)}")
        return jsonify({'error': str(e)}), 500


@app.route('/api/save-face-image', methods=['POST'])
def save_face_image_endpoint():
    """
    Save a face image to disk
    Request: { image: base64_string, student_id: str, face_type: 'front'|'left'|'right' }
    Response: { path: str, status: 'success' }
    """
    try:
        data = request.get_json()

        if not data or 'image' not in data:
            return jsonify({'error': 'No image provided'}), 400

        student_id = data.get('student_id', 'unknown')
        face_type = data.get('face_type', 'front')

        image = decode_base64_image(data['image'])
        if image is None:
            return jsonify({'error': 'Invalid image data'}), 400

        student_dir = os.path.join(UPLOAD_FOLDER, student_id)
        os.makedirs(student_dir, exist_ok=True)

        filename = f"{face_type}_{datetime.now().strftime('%Y%m%d_%H%M%S')}.jpg"
        filepath = os.path.join(student_dir, filename)
        cv2.imwrite(filepath, image)

        return jsonify({
            'status': 'success',
            'path': filepath,
            'filename': filename,
            'message': 'Face image saved successfully'
        })

    except Exception as e:
        logger.error(f"Save face image error: {str(e)}")
        return jsonify({'error': str(e)}), 500


@app.route('/api/batch-encode', methods=['POST'])
def batch_encode_endpoint():
    """
    Encode multiple faces (front, left, right) and create combined encoding
    Request: { images: {front: base64, left: base64, right: base64} }
    Response: { encoding: [...], status: 'success' }
    """
    try:
        data = request.get_json()

        if not data or 'images' not in data:
            return jsonify({'error': 'No images provided'}), 400

        images = data['images']
        encodings = []
        errors = []
        quality_warnings = []

        for face_type, base64_image in images.items():
            if not base64_image:
                continue

            image = decode_base64_image(base64_image)
            if image is None:
                errors.append(f"Invalid {face_type} image")
                continue

            # Check quality before encoding - skip quality failures
            quality_ok, quality_issues, metrics = check_image_quality(image)
            if not quality_ok:
                quality_warnings.extend([f"{face_type}: {issue}" for issue in quality_issues])
                errors.append(f"{face_type}: Quality issue - {'; '.join(quality_issues)}")
                continue

            encoding, error = encode_face(image, check_quality=False)
            if error:
                errors.append(f"{face_type}: {error}")
            else:
                encodings.append(encoding)

        if not encodings:
            return jsonify({
                'error': 'No faces could be encoded',
                'details': errors,
                'quality_warnings': quality_warnings
            }), 400

        # Average the encodings for a combined representation
        combined_encoding = np.mean(encodings, axis=0).tolist()

        return jsonify({
            'status': 'success',
            'encoding': combined_encoding,
            'faces_encoded': len(encodings),
            'errors': errors,
            'quality_warnings': quality_warnings,
            'message': f'Successfully encoded {len(encodings)} face(s)'
        })

    except Exception as e:
        logger.error(f"Batch encode error: {str(e)}")
        return jsonify({'error': str(e)}), 500


# ============================================================
# ERROR HANDLERS
# ============================================================

@app.errorhandler(404)
def not_found(error):
    return jsonify({'error': 'Endpoint not found'}), 404


@app.errorhandler(500)
def server_error(error):
    return jsonify({'error': 'Internal server error'}), 500


# ============================================================
# MAIN
# ============================================================

if __name__ == '__main__':
    logger.info("=" * 60)
    logger.info("LDB-FRAS Face Recognition Engine Starting...")
    logger.info("=" * 60)
    logger.info(f"Model: {MODEL_NAME}")
    logger.info(f"Detector: {DETECTOR_BACKEND}")
    logger.info(f"Tolerance: {TOLERANCE}")
    logger.info(f"Min confidence: {MIN_CONFIDENCE}%")
    logger.info(f"Min match margin: {MIN_MATCH_MARGIN}")
    logger.info(f"Quality: brightness=[{BRIGHTNESS_MIN}-{BRIGHTNESS_MAX}], contrast={CONTRAST_MIN}, face_size>={FACE_SIZE_MIN}px, max_detection={MAX_DETECTION_SIZE}px, zone_margin={FACE_ZONE_MARGIN}")
    logger.info(f"Upload folder: {os.path.abspath(UPLOAD_FOLDER)}")
    
    # Warm up models before binding to port
    warmup_success = warmup_models()
    model_status = 'Loaded & warmed up' if warmup_success else 'Loaded (warm-up failed)'
    logger.info(f"Model status: {model_status}")
    
    port = int(os.environ.get('PORT', 5000))
    debug = os.environ.get('FLASK_DEBUG', '0') == '1'
    logger.info("=" * 60)
    logger.info(f"Service ready at http://0.0.0.0:{port}")
    logger.info("Press Ctrl+C to stop")
    logger.info("=" * 60)
    
    app.run(host='0.0.0.0', port=port, debug=debug, use_reloader=False)
