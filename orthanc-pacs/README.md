# Orthanc PACS & Modality Worklist (MWL) Synchronization

A professional, enterprise-grade Orthanc PACS server integration with dual-mode DICOM Modality Worklist (MWL) synchronization for [SIMRS Khanza](https://github.com/mas-elkhanza/SIMRS-Khanza/).

> **Acknowledgment:** Special thanks to mas-elkhanza and the SIMRS Khanza developer ecosystem for the base MWL script logic.

---

## ─── 🏛️ Dual-Mode Architecture ───

This PACS module features a state-of-the-art dual-mode synchronization design. Systems administrators can toggle between a real-time, in-memory Python C-FIND SCP plugin or a filesystem-based flat-file generator depending on infrastructure needs.

```
┌────────────────────────────────────────────────────────────────────────┐
│                              PRODUCTION MODE                           │
│                      (Dynamic Real-Time C-FIND SCP)                    │
│                                                                        │
│  ┌──────────────┐          SQL          ┌──────────────────────────┐   │
│  │  SIMRS DB    │◄──────────────────────│   Orthanc PACS Server    │   │
│  │  (External)  │                       │   (Dynamic Python Plugin)│   │
│  └──────────────┘                       └────────────┬─────────────┘   │
│                                                      │ C-FIND SCP      │
│                                                      ▼                 │
│                                             [ DICOM Modality ]         │
└────────────────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────────────────┐
│                           LEGACY FALLBACK MODE                         │
│                         (Static Flat-File MWL)                         │
│                                                                        │
│  ┌──────────────┐   SQL    ┌──────────────┐  .wl   ┌────────────────┐  │
│  │  SIMRS DB    │◄─────────│  simrs-mwl   │───────►│  Orthanc PACS  │  │
│  │  (External)  │          │ (PHP Daemon) │  files │  (C++ Plugin)  │  │
│  └──────────────┘          └──────────────┘        └────────┬───────┘  │
│                                                             │ C-FIND   │
│                                                             ▼          │
│                                                     [ DICOM Modality ] │
└────────────────────────────────────────────────────────────────────────┘
```

### 🎛️ Mode Comparison & Toggling

| Feature | Mode A: Dynamic Python SCP (Recommended) | Mode B: Static PHP Daemon (Fallback) |
| :--- | :--- | :--- |
| **Data Flow** | Direct in-memory C-FIND query execution. | Periodic DB scanning, writes `.wl` files to disk. |
| **Sync Latency** | **0 seconds (Instant real-time)** | 10+ seconds (determined by cron interval). |
| **Disk Overhead** | **None** (zero physical writes, preserves SSD lifespan).| High (continuous disk writes and cleanup loops). |
| **Offline Support**| Fully pre-baked inside an immutable container. | Relies on internal cleanups and storage sharing. |
| **Status** | **Production standard** | Disabled (maintained as developer fallback). |

#### How to Toggle Modes in `docker-compose.yml`:
Open `docker-compose.yml` and toggle the following environment switches under the `orthanc` service:

* **To Enable Dynamic Python SCP (Production Standard)**:
  ```yaml
  PYTHON_PLUGIN_ENABLED: "true"
  WORKLISTS_PLUGIN_ENABLED: "false"
  ```
* **To Enable Static PHP Daemon (Legacy Fallback)**:
  ```yaml
  PYTHON_PLUGIN_ENABLED: "false"
  WORKLISTS_PLUGIN_ENABLED: "true"
  ```

---

## ─── 🚀 Core Integration Features ───

### 🧬 Deterministic ISO 2.25 DICOM OIDs
Rather than utilizing arbitrary or sequential study ID generators, both sync paths apply a globally unique, deterministic ISO `2.25` root branch based on RFC 4122 UUID Version 3:
$$2.25.[\text{unsigned-128bit-decimal-UUID-from-PatientID-and-AccessionNumber}]$$
This guarantees character-for-character OID matching between pre-acquisition worklists, SIMRS Satu Sehat Java bridges, and post-acquisition Orthanc modifier engines, while strictly maintaining UIDs well within the standard DICOM 64-character limit.

### 🌐 3-Tier AE Title Lookup Configuration
Modality configuration is unified under [mapping_tindakan_radiologi.iyem](file:///home/malifnasrulloh/Downloads/addon-simrs-khanza/orthanc-pacs/orthanc-config/mapping_tindakan_radiologi.iyem). On incoming queries, Orthanc automatically evaluates:
1. Individual **Procedure Mappings** (binds custom radiologic codes to specific AETs like `CR_STATION`).
2. General **Modality Category Mappings** (e.g., all `CR` actions fallback to a primary CR station AET).
3. Universal **Default Fallbacks** if no matching rule exists.

### 📋 Full DICOM Standard Compliance
Sync pipelines compile complete nested sequence attributes, including:
* **Study Date & Time** `(0008,0020)` / `(0008,0030)`
* **Institution Name** `(0008,0080)` (dynamically queried from the SIMRS production `setting` table)
* **Referring & Requesting Physician** `(0008,0090)` / `(0032,1032)`
* **SPS Comments & Reason for Procedure** `(0040,0400)` / `(0040,1002)` mapped directly to the patient's clinical diagnosis.

---

## ─── 📂 Directory Structure ───

```
orthanc-pacs/
├── Dockerfile                  # Builds custom Orthanc image with pre-baked pymysql
├── docker-compose.yml          # Production orchestration stack (Orthanc + MariaDB)
├── .env.example                # Configuration template (copy to .env)
├── README.md                   # System integration documentation
├── orthanc-config/
│   ├── orthanc.json            # Orthanc configuration and AET find authorization
│   ├── mapping_tindakan_radiologi.iyem # Unified procedure & AET mappings
│   └── worklist_plugin.py      # Real-time C-FIND SCP Python plugin
└── mwl/
    ├── Dockerfile              # Static PHP daemon container image
    ├── entrypoint.sh           # Daemon cron bootstrapper
    ├── index.php               # PHP flat-file MWL generator + dashboard
    └── mapping_tindakan_radiologi.iyem # PHP-side lookup definitions
```

---

## ─── 🛠️ Quick Deployment (Production) ───

### 1. Configure the Environment
Copy the configuration template and update your credentials, making sure `host.docker.internal` gateway routing is configured to reach your host's SIMRS database:
```bash
cp .env.example .env
nano .env
```

### 2. Configure Procedure Mappings
Edit the mapping file to link your radiology procedure codes (`kd_jenis_prw`) to correct Modality letters and Station AE Titles:
```bash
nano orthanc-config/mapping_tindakan_radiologi.iyem
```

### 3. Deploy the Stack
Compile the custom, pre-baked Docker container and start the background services:
```bash
docker compose up -d --build
```
The custom `Dockerfile` automatically downloads and configures the native system dependencies (`python3-pymysql`) in less than 60 seconds, delivering a highly responsive, offline-ready PACS container.

### 4. Verify Real-time Handshakes
You can immediately trigger a test query from any host machine using DCMTK's `findscu` client:
```bash
findscu -v -W -k 0010,0010="" -k 0008,0050="" -k 0010,0020="" localhost 4242
```
Look at your container logs to watch the instant, real-time database query handshake process:
```bash
docker compose logs -f orthanc
```

---

## ─── 🔒 Security & Performance Guidelines ───
* **No Outbound WAN Dependency**: Mode A relies entirely on native Debian pre-baked system packages. Once built, the stack can run perfectly on air-gapped hospital intranets with no external internet connection.
* **Network Segmentation**: Orthanc and its internal MariaDB index database are locked within a secure, custom Docker backend network, protecting patient logs from external port scans.
* **Strict AET Query Authorization**: C-FIND queries are registered under permitted `DicomModalities` in `orthanc.json`, preventing unauthorized network access to patient indices.

---

## ─── ⚖️ License ───
MIT License. Dedicated to MAS-Elkhanza and the open-source clinical development community.
