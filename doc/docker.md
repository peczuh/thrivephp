# Run ThrivePHP tests in Docker on Alpine

Use Alpine's Chromium or Firefox packages for local browser tests. The test
runner discovers installed browser and driver pairs. It does not install them.
Use a ThrivePHP release that recognizes Alpine in WebDriver discovery.

## Build a test image

This example is for an application with `peczuh/thrivephp` in `composer.json` and
a committed `composer.lock`. Build from the application root, not a ThrivePHP
checkout. Keep host `vendor/`, `work/`, `.git/`, and local secrets out of the
build context with `.dockerignore`.

```dockerfile
FROM composer:2 AS composer
FROM alpine:3.24

RUN apk add --no-cache \
        php85 php85-curl php85-mbstring php85-openssl php85-phar \
        git unzip procps-ng \
        chromium chromium-chromedriver firefox geckodriver \
    && ln -s /usr/bin/php85 /usr/local/bin/php \
    && printf 'short_open_tag=On\n' > /etc/php85/conf.d/99-thrive.ini \
    && adduser -D -h /home/tester tester \
    && mkdir -p /app /reports \
    && chown tester:tester /app /reports

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY --chown=tester:tester . .
USER tester
RUN composer install --no-interaction --prefer-dist

ENTRYPOINT ["php", "vendor/peczuh/thrivephp/bin/cli.php", "--root", ".", "test"]
```

Keep the browser and driver packages for the targets you test. Install any PHP
extensions required by your application, such as `php85-pgsql` for ThrivePHP's
PostgreSQL components. Keep development dependencies if your test suites use
them.

Keep `procps-ng` for parallel runs. The runner uses `/bin/ps` to track and clean
up worker descendants. Alpine's BusyBox `ps` does not accept the arguments the
runner uses. Without `procps-ng`, select `--workers 1` to run in serial mode.

## Run the tests

```sh
docker build -t my-app-tests .
docker run --rm --init my-app-tests --browser firefox --headless --workers 2
```

Use `--browser chromium` to select Chromium. `--browser chrome` selects Google
Chrome, not Chromium. Reports go to `work/test` under the application root by
default. To keep them after the container exits, mount a directory that the
container user can write to and pass `--savepath /reports`:

```sh
mkdir -p reports
docker run --rm --init \
    --mount "type=bind,src=$PWD/reports,dst=/reports" \
    my-app-tests --browser firefox --headless --savepath /reports
```

For tests against a separate web service, put the test container and the service
on the same Docker network and configure the suites with the service's network
name, such as `http://web:8080`. `localhost` inside the test container refers to
that container, not the host or another container. WebDriver itself stays local
to the test container. There is no need to publish driver ports.

### Firefox startup timeout

Firefox took about 11.5 seconds to create a headless session in the isolated
Alpine ARM64 smoke test. If session creation exceeds the default 10-second
command timeout in your container, set a longer timeout in your `SuiteBrowser`
subclass:

```php
protected function configure(\ThriveData\ThrivePHP\Test\SuiteConfig $config): void
{
    $config->timeout = 30;
}
```

This timeout is in seconds and applies to WebDriver commands, including session
creation. It is separate from assertion and polling timeouts.

### Chromium sandbox

Run the browser as a non-root user. Docker's default security settings can
still prevent Chromium from creating its sandbox namespaces, causing ChromeDriver
to report `session not created`. Prefer a container configuration that supports
Chromium's sandbox, or use Firefox. The framework's smoke test passes
`--no-sandbox` only in a container with networking disabled and uses local
`data:` pages. Do not use that flag as a default for application tests that load
untrusted pages.

### Google Chrome

Google's Chrome for Testing and its matching ChromeDriver are separate from
Alpine's Chromium packages. The Google Linux binaries require the glibc loader
and did not start on stock Alpine with either ARM64 or emulated AMD64. Alpine's
`gcompat` did not make the browser usable in either test. Use a glibc-based
image such as Debian when you need actual Google Chrome.

## Framework regression

From a ThrivePHP checkout, run the
[on-demand Docker regression](testing.md#on-demand-docker-regression) to verify
real headless Chromium and Firefox sessions and check the expected Google Chrome
startup results. This is separate from running your application's tests.
