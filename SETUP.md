# SETUP.md: setting up Glimpse on a server

For an AI agent (or an admin who wants a checklist) that installs Glimpse end to end. The
human guide with the reasons behind each step is the README (*Hardware*, *Requirements*,
*Install*). Working on the code instead? Read [AGENTS.md](AGENTS.md).

Work through the steps in order. Each ends with a check; do not go on while a check fails.

## 0. Look before you change anything

```bash
nproc; free -g; df -h                         # CPU, RAM, disk (see README → Hardware)
nvidia-smi 2>/dev/null || echo "no NVIDIA GPU"
docker ps --format '{{.Names}}\t{{.Image}}'   # is Nextcloud / Immich already running?
```

Decide from this, and tell the user what you picked and why:

| Machine | Immich image | Search model |
|---|---|---|
| No NVIDIA GPU, under 12 GB RAM | `immich-machine-learning:<version>` | `ViT-B-32__openai` (default, English) |
| No NVIDIA GPU, 12 GB RAM or more | `immich-machine-learning:<version>` | default, or `ViT-SO400M-16-SigLIP2-384__webli` if the user accepts indexing about 4 times slower than the default on a CPU (7 s vs 1.9 s per picture) |
| NVIDIA GPU with 2 to 6 GB free VRAM | `immich-machine-learning:<version>-cuda` | `ViT-B-32__openai` (default, about 1.5 GB VRAM with face recognition) |
| NVIDIA GPU with 6 GB free VRAM or more | `immich-machine-learning:<version>-cuda` | `ViT-SO400M-16-SigLIP2-384__webli` (better, multilingual; about 5.7 GB VRAM with face recognition) |

With a GPU, Immich's compose file also needs the GPU reservation from its hardware acceleration guide (step 1).

Ask the user before you:
- install Docker, drivers or the NVIDIA container toolkit,
- restart Nextcloud or other containers that are already serving people,
- choose which Nextcloud users get a library.

Find `occ`. For Nextcloud AIO:

```bash
occ() { docker exec -i --user www-data nextcloud-aio-nextcloud php occ "$@"; }
```

Otherwise it is `sudo -u www-data php /var/www/nextcloud/occ` or the equivalent. The steps
below write `occ` for it.

**Check:** `occ status` shows `installed: true`, and the version is 30 to 35.

## 1. Immich

Skip this if Immich already runs. Otherwise follow Immich's own guide
(<https://immich.app/docs/install/docker-compose>); for a GPU, also
<https://immich.app/docs/features/ml-hardware-acceleration>.

Things that matter for Glimpse:

- Put Immich's data (`UPLOAD_LOCATION`, `DB_DATA_LOCATION`) on an SSD. Thumbnails are tens of
  thousands of small files, read at random.
- Do not publish port 2283 on `0.0.0.0`. Docker bypasses most host firewalls. Bind it to the
  LAN address or leave it on a Docker network that Nextcloud can reach.
- Create the first Immich user. It becomes the admin and the owner of all libraries.

**Check:** `curl -s http://<immich>:2283/api/server/ping` answers `{"res":"pong"}`.

## 2. Mount the Nextcloud files into Immich, read-only

Find the Nextcloud data directory:

```bash
# For Nextcloud AIO (host mountpoint):
docker volume inspect nextcloud_aio_nextcloud_datadir --format '{{.Mountpoint}}'

# Otherwise (bare-metal or standard Nextcloud):
occ config:system:get datadirectory
```

(For AIO with `NEXTCLOUD_DATADIR` set, use that host path instead.)

For each user who gets photo search, add one line under `immich-server: volumes:` in Immich's
`docker-compose.yml`:

```yaml
      - <datadir>/<user>/files:/mnt/nextcloud/<user>:ro
```

Always `:ro`. Glimpse never needs Immich to write there. Then `docker compose up -d immich-server`.

**Check:** `docker exec immich_server ls /mnt/nextcloud/<user>` lists that user's folders.

## 3. Search model (only if you chose the large one)

In Immich: *Administration → Settings → Machine Learning → Smart Search → model*, set
`ViT-SO400M-16-SigLIP2-384__webli`, save. If pictures were indexed before, run *Administration →
Job Queues → Smart Search → All*.

## 4. API key

In Immich, as the admin: *Account settings → API keys → New*, permission **all**. Store it in a
file with mode 600; never print it, put it on a command line or commit it.

## 5. Install and connect Glimpse

```bash
occ app:install glimpse          # from the App Store
# or: unpack the release tarball into custom_apps/ and run
occ app:enable glimpse

occ glimpse:configure --url http://<immich>:2283 --key-stdin < /path/to/api-key
occ glimpse:configure --path-pattern '/mnt/nextcloud/{user}/'
```

The URL must be reachable **from the Nextcloud container**, not from your shell. If both run in
Docker, put them on a shared network and use the container name (e.g. `http://immich_server:2283`).

**Check:** `occ glimpse:configure` with no options prints `Configured, path pattern …`, and the web UI under
*Glimpse → Settings → Connection → Test connection* reports success.

## 6. Libraries

In *Glimpse → Settings → What gets indexed → Libraries per user*, press *Create library* for each
user from step 2. Users appear there only after they have logged in once.

**Check:** *Settings → Status* shows Immich's queues filling and then draining. On a CPU this
takes hours for a large collection (README → Hardware has measured rates).

## 7. Optional extras

- **Search inside videos:** needs `ffmpeg` in the Nextcloud container (`which ffmpeg`; AIO has
  it). Enable under *Settings → What gets indexed*. Runs at night by default; to start now:
  `occ glimpse:video-scenes --minutes=30`, progress with `occ glimpse:video-scenes --status`.
- **Sensitive pictures:** needs the database role from the next point with
  `GRANT SELECT ON smart_search` and `GRANT SELECT ("originalFileName", "deletedAt", type, "fileCreatedAt", "localDateTime") ON asset`,
  and the ML service reachable from the Nextcloud container (set its address under *Settings →
  Connection*). Check with `occ glimpse:sensitive --check`, then allow it under *Settings → What
  gets indexed*. Each user turns it on for themselves; do not turn it on for anyone.
- **Keep pictures when they are moved:** README → *Install* → step 5 (a Postgres role that may
  only update asset paths, then `occ glimpse:configure --db-host … --db-user … --db-password-stdin < /path/to/password`).

## 8. Final check

```bash
occ glimpse:people-index        # builds people and map now instead of in an hour
```

Check *Settings → Status* to see that Immich is actively draining its queues. Once initial
pictures are indexed, log in as one of the users, open Glimpse and search for something that is
surely in the photos ("tree", "car"). Results mean everything works. Report to the user: which
model, how many pictures are queued, and roughly how long indexing will take.

## Pitfalls

- **Nextcloud test logins share the server's IP.** Repeated failed logins from the server
  throttle every client behind the same address. Use an app password
  (`occ user:auth-tokens:add <user>`) and get it right the first time.
- **Container memory limits.** Immich's ML service needs about 1.5 GB with the default model
  and 6 GB with the large one on a CPU (4 GB is enough with a GPU). A lower `mem_limit` gets it
  killed while indexing, which looks like a queue that never drains.
- **Paths must match exactly.** The path pattern is what Immich sees inside its container, not
  the host path. A wrong pattern shows up as an empty timeline although Immich has the pictures.
- **New pictures** arrive with Immich's nightly library scan. *Settings → Status → Scan libraries
  now* starts one at once.
