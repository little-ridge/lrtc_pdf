# lrtc_pdf

PDF API for an always-on Incus system container. Callers send JSON and get a PDF back.

The service listens on port 8080 inside the container. Tokens are stored on that container.

## Tokens

Bearer tokens are checked against `/etc/lrtc-pdf/tokens.json`. That file stores the SHA-256 of each token plus its name, owner, and scopes. It does not store the raw token. The API never reads or writes a `token.txt`, and nothing under `public/` can see the hash file.

The file is mode `0440`, owned by `root:lrtc-pdf`, so the service can read it and other users cannot. Creating, disabling, or removing a token is a root command on the container, not an API call.

```sh
php scripts/token.php add --name LRTC --owner lrtc
php scripts/token.php add --name LRTC --owner lrtc --from /path/to/token.txt
php scripts/token.php list
php scripts/token.php disable --name LRTC
```

Run as your own user, the hash file is `~/.config/lrtc-pdf/tokens.json` and is readable only by you. Run as root, it is `/etc/lrtc-pdf/tokens.json`. The container service reads the `/etc` file.

`add` prints a new token once. `--from` hashes a token file you already keep elsewhere and does not copy that file into the container. Keep the raw token on the machine that calls the API.

A valid token may fetch any `http` or `https` URL. `file:` URLs stay blocked. The default rate limit is 30 requests per 60 seconds.

Saved templates and rate-limit counters go in `/var/lib/lrtc-pdf`, which is separate from the token file. The bundled `greeting` template is always available.

## Run

Launch an Ubuntu container and copy this project into it. Any Ubuntu release you already use is fine when its PHP is 8.1 or newer.

```sh
incus launch images:ubuntu/26.04 lrtc-pdf -c boot.autostart=true
incus file push -p -r . lrtc-pdf/opt/lrtc-pdf
incus exec lrtc-pdf -- bash /opt/lrtc-pdf/scripts/incus-setup.sh
```

Publish the API on the host's loopback interface:

```sh
incus config device add lrtc-pdf http proxy listen=tcp:127.0.0.1:8080 connect=tcp:127.0.0.1:8080
```

Use a different `listen` address when callers are not on that host. The token file is still not served.
