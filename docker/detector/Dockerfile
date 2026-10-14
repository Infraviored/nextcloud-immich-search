# Nudity detector for Glimpse's sensitive pictures (see README, "Sensitive pictures").
FROM python:3.12-slim
RUN pip install --no-cache-dir nudenet==3.4.2 \
    && python -c "from nudenet import NudeDetector; NudeDetector()"
COPY detector.py /app/detector.py
USER nobody
EXPOSE 3005
HEALTHCHECK --interval=60s --timeout=5s CMD python -c "import urllib.request; urllib.request.urlopen('http://localhost:3005/ping')"
CMD ["python", "/app/detector.py"]
