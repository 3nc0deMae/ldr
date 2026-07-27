"""
LDB-FRAS - Keep-Alive Monitor
Pings the face recognition service to keep it warm
Run this with Task Scheduler to prevent cold starts
"""

import requests
import time
import logging
import sys
from datetime import datetime

# Configuration
API_URL = "http://localhost:5000"
HEALTH_ENDPOINT = f"{API_URL}/api/health"
PING_INTERVAL = 300  # 5 minutes (prevents TF from unloading model)

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('keepalive.log'),
        logging.StreamHandler(sys.stdout)
    ]
)
logger = logging.getLogger(__name__)


def ping_service():
    """Send health check ping to keep service warm"""
    try:
        response = requests.get(HEALTH_ENDPOINT, timeout=10)
        if response.status_code == 200:
            logger.debug("Service is alive")
            return True
        else:
            logger.warning(f"Service returned status: {response.status_code}")
            return False
    except requests.exceptions.ConnectionError:
        logger.error("Service is not responding!")
        return False
    except Exception as e:
        logger.error(f"Ping failed: {e}")
        return False


def main():
    logger.info("=" * 60)
    logger.info("LDB-FRAS Keep-Alive Monitor Started")
    logger.info(f"Ping interval: {PING_INTERVAL} seconds")
    logger.info("=" * 60)

    consecutive_failures = 0
    max_failures = 3

    while True:
        try:
            success = ping_service()

            if success:
                consecutive_failures = 0
            else:
                consecutive_failures += 1
                if consecutive_failures >= max_failures:
                    logger.critical(
                        f"Service failed {consecutive_failures} times consecutively!"
                    )
                    # Could trigger alert or restart here

            time.sleep(PING_INTERVAL)

        except KeyboardInterrupt:
            logger.info("Keep-alive monitor stopped by user")
            break
        except Exception as e:
            logger.error(f"Unexpected error: {e}")
            time.sleep(60)


if __name__ == "__main__":
    main()
