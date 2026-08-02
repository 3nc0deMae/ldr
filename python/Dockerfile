FROM python:3.10-slim

# Install system dependencies (using modern Debian OpenGL package libgl1)
RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential \
    libgl1 \
    libglib2.0-0 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Copy requirements and install
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy all python files
COPY . .

# Start app.py
CMD ["python", "app.py"]
