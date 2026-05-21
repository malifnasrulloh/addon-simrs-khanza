# Orthanc PACS & MWL Sync

A production-ready Orthanc PACS server with automated DICOM Modality Worklist (MWL) synchronization for [SIMRS Khanza](https://github.com/mas-elkhanza/SIMRS-Khanza/).

> **Acknowledgment:** Thank you to [mas-elkhanza](https://github.com/mas-elkhanza/) for the base MWL code and the SIMRS Khanza project.

## Architecture

```
┌──────────────┐         ┌──────────────────┐         ┌──────────────┐
│  SIMRS DB    │◄────────│   simrs-mwl      │────────►│  Orthanc     │
│  (External)  │  SQL    │  (PHP Daemon +   │  .wl    │  PACS Server │
│              │         │   Dashboard)     │  files  │              │
└──────────────┘         └──────────────────┘         └──────┬───────┘
                                                             │
                                                      ┌──────┴───────┐
                                                      │  MariaDB     │
                                                      │  (Internal)  │
                                                      └──────────────┘
```

- **simrs-mwl** queries the SIMRS Khanza database for radiology orders every N seconds, generates DICOM worklist files, and writes them to a shared volume.
- **Orthanc** reads the worklist directory and serves MWL C-FIND responses to modalities.
- **MariaDB** stores Orthanc's internal index (studies, series, instances).

## Prerequisites

- Docker and Docker Compose v2+
- Network access to the SIMRS Khanza database (MariaDB/MySQL)

## Quick Start

```bash
# 1. Clone and enter directory
cd orthanc-pacs

# 2. Copy and edit the environment file
cp .env.example .env
nano .env

# 3. Update modality AE Title mappings
nano mwl/modality_aet.json

# 4. Deploy
docker compose up -d --build

# 5. Check logs
docker compose logs -f simrs-mwl
```

## Access

| Service         | URL                               | Default Credentials |
|-----------------|-----------------------------------|---------------------|
| Orthanc Explorer | `http://<server>:8042`           | Set in `.env` (`ORTHANC_WEB_USER` / `ORTHANC_WEB_PASS`) |
| MWL Dashboard    | `http://<server>:8081`           | Set in `.env` (`MWL_WEB_USER` / `MWL_WEB_PASS`) |

## Configuration Reference

All configuration is managed through the `.env` file — no need to enter any container.

### Orthanc Internal Database

| Variable | Description | Default |
|----------|-------------|---------|
| `MARIADB_ROOT_PASSWORD` | MariaDB root password | *(required)* |
| `MARIADB_DATABASE` | Database name for Orthanc index | `orthanc_db` |
| `MARIADB_USER` | Database user | *(required)* |
| `MARIADB_PASSWORD` | Database password | *(required)* |

### SIMRS Khanza Database

| Variable | Description | Default |
|----------|-------------|---------|
| `SIMRS_DB_HOST` | SIMRS database hostname/IP | *(required)* |
| `SIMRS_DB_PORT` | SIMRS database port | `3306` |
| `SIMRS_DB_USER` | SIMRS database user | *(required)* |
| `SIMRS_DB_PASS` | SIMRS database password | *(required)* |
| `SIMRS_DB_NAME` | SIMRS database name | `sik` |

### Orthanc PACS

| Variable | Description | Default |
|----------|-------------|---------|
| `ORTHANC_AET` | DICOM Application Entity Title | `ORTHANC` |
| `ORTHANC_HTTP_PORT` | Orthanc web UI port | `8042` |
| `ORTHANC_DICOM_PORT` | DICOM protocol port | `4242` |
| `ORTHANC_WEB_USER` | Orthanc Explorer username | `admin` |
| `ORTHANC_WEB_PASS` | Orthanc Explorer password | `changeme` |

### MWL Service

| Variable | Description | Default |
|----------|-------------|---------|
| `MWL_DASHBOARD_PORT` | Dashboard web port | `8081` |
| `MWL_WEB_USER` | Dashboard username | `admin` |
| `MWL_WEB_PASS` | Dashboard password | `changeme` |
| `MWL_SYNC_INTERVAL` | Seconds between sync cycles | `10` |
| `MWL_DASHBOARD_REFRESH_SEC` | Dashboard auto-refresh interval (seconds) | `300` |
| `MWL_STALE_DAYS` | Days to keep old worklist files | `2` |
| `DICOM_UID_ROOT` | OID root for DICOM UID generation | `2.25` |

### General

| Variable | Description | Default |
|----------|-------------|---------|
| `TZ` | Timezone | `Asia/Jakarta` |

## Project Structure

```
orthanc-pacs/
├── .env.example              # Environment template (copy to .env)
├── .dockerignore             # Docker build exclusions
├── .gitignore                # Git exclusions
├── docker-compose.yml        # Production orchestration
├── docker-compose.override.yml  # Dev overrides (source mount)
├── LICENSE                   # MIT License
├── README.md
├── orthanc-config/
│   └── orthanc.json          # Orthanc base configuration
└── mwl/
    ├── Dockerfile            # MWL service image
    ├── entrypoint.sh         # Container startup script
    ├── index.php             # MWL generator + dashboard
    └── modality_aet.json     # Modality → AE Title mapping
```

## Features

- **Automated MWL Sync** — Background daemon generates DICOM worklist files from SIMRS radiology orders
- **Full DICOM Compliance** — Includes complete Scheduled Procedure Step Sequence with all mandatory tags
- **Web Dashboard** — Real-time monitoring with stats cards and auto-refresh
- **Stale Cleanup** — Automatically removes expired worklist files
- **Security** — Authentication on both Orthanc and dashboard, network isolation between services
- **Externalized Config** — Every setting configurable via `.env` without entering containers
- **Production Docker** — Pinned images, healthchecks, proper entrypoint, network segmentation

## Modality Configuration

Edit `mwl/modality_aet.json` to map modality types to their AE Titles:

```json
{
  "CR": { "aet": "CR_STATION", "host": "192.168.1.100", "port": 104 },
  "CT": { "aet": "CT_STATION", "host": "192.168.1.101", "port": 104 }
}
```

Orthanc must also know about these modalities. Edit `orthanc-config/orthanc.json` to register them under `DicomModalities`.

## Troubleshooting

| Symptom | Solution |
|---------|----------|
| `ERROR: Database connection failed` in logs | Check `SIMRS_DB_*` variables in `.env`, ensure DB is reachable |
| Modality can't find worklist entries | Verify `modality_aet.json` AET matches the modality's configured AET |
| Dashboard shows 401 Unauthorized | Check `MWL_WEB_USER` / `MWL_WEB_PASS` in `.env` |
| Orthanc Explorer unreachable | Check `ORTHANC_HTTP_PORT` and `ORTHANC_WEB_USER` / `ORTHANC_WEB_PASS` |
| `dump2dcm` errors in logs | Verify DCMTK is installed in container: `docker compose exec simrs-mwl dump2dcm --version` |

## Development

For live code editing during development, the `docker-compose.override.yml` mounts the `./mwl` directory into the container:

```bash
# Override is loaded automatically
docker compose up -d --build
```

To disable the override for production:

```bash
docker compose -f docker-compose.yml up -d --build
```

## License

[MIT](LICENSE)
