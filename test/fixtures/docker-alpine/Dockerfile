FROM alpine:3.24 AS base
RUN apk add --no-cache php85 php85-curl \
    && adduser -D -h /home/smoke smoke
WORKDIR /home/smoke

FROM base AS chromium
RUN apk add --no-cache chromium font-dejavu

FROM chromium AS native
RUN apk add --no-cache chromium-chromedriver firefox geckodriver procps-ng
USER smoke

FROM base AS chrome-stock
# Keep this pair fixed so a release change cannot masquerade as a libc regression.
ARG CHROME_VERSION=158.0.8127.36
ARG TARGETARCH
RUN set -e; \
    case "$TARGETARCH" in \
        amd64) platform=linux64 ;; \
        arm64) platform=linux-arm64 ;; \
        *) echo "Chrome for Testing $CHROME_VERSION has no Linux download for $TARGETARCH." >&2; exit 1 ;; \
    esac; \
    apk add --no-cache binutils curl unzip; \
    mkdir -p /opt/chrome-for-testing; \
    for component in chrome chromedriver; do \
        curl -fsS --retry 2 --max-time 120 -o /tmp/chrome.zip \
            "https://storage.googleapis.com/chrome-for-testing-public/${CHROME_VERSION}/${platform}/${component}-${platform}.zip"; \
        unzip -q /tmp/chrome.zip -d /tmp; \
        mv "/tmp/${component}-${platform}" "/opt/chrome-for-testing/${component}"; \
        rm /tmp/chrome.zip; \
    done
RUN ln -s /opt/chrome-for-testing/chrome/chrome /usr/local/bin/google-chrome \
    && ln -s /opt/chrome-for-testing/chromedriver/chromedriver /usr/local/bin/chromedriver
USER smoke

# Use Chromium's system libraries, but keep its own ChromeDriver out of this image.
FROM chromium AS chrome-compat
RUN apk add --no-cache binutils gcompat
COPY --from=chrome-stock /opt/chrome-for-testing/ /opt/chrome-for-testing/
RUN ln -s /opt/chrome-for-testing/chrome/chrome /usr/local/bin/google-chrome \
    && ln -s /opt/chrome-for-testing/chromedriver/chromedriver /usr/local/bin/chromedriver
USER smoke
