# Patient SatuSehat Portal

A modern, fast, and secure web application built for hospital staff to effortlessly look up and synchronize patient records with the Ministry of Health's **Satu Sehat** platform and the **SIMRS Khanza** database.

## 🚀 Features

- **ISO-Grade Authentication:** Secures endpoints using your existing SIMRS Khanza `user` credentials with built-in AES decryption.
- **Dynamic Search:** Find patients using No. Rekam Medis (local-first), NIK, or NIK Ibu (direct from Satu Sehat).
- **One-Click Synchronization:** Automatically updates the `satu_sehat_ihs_patient` mapping table in your database.
- **Create Patients Seamlessly:** If a patient is missing in Satu Sehat, create their profile with a single click and have their new IHS Number mapped locally in an instant.
- **Glassmorphism UI:** Built with Vite + React + Vanilla CSS tokens for a premium, fast, and highly responsive user experience.

---

## 🛠️ Tech Stack

- **Frontend:** React (powered by Vite), React Router DOM, Vanilla CSS.
- **Backend:** PHP (Native PDO), leveraging the existing `SatuSehatClient.php`.
- **Database:** MySQL/MariaDB (SIMRS Khanza `sik` database).

---

## 📦 Installation & Setup

### 1. Backend Setup
Ensure your PHP service configuration is properly set up. 
1. The backend API is located at `../php-service/api_satusehat_portal.php`.
2. Ensure your `.env` inside `php-service` is correctly configured with:
   - `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
   - Satu Sehat API Keys and Base URL (`SATUSEHAT_BASE_URL`, `SATUSEHAT_CLIENT_ID`, `SATUSEHAT_CLIENT_SECRET`)
3. The PHP endpoint relies on the `satu_sehat_ihs_patient` and `user` tables in your `sik` database (which usually exists in SIMRS Khanza or your custom fork `SIMRS-Khanza-fork`).

### 2. Frontend Setup
1. Open a terminal and navigate to this project directory:
   ```bash
   cd /path/to/addon-simrs-khanza/satusehat-portal
   ```
2. Install dependencies:
   ```bash
   npm install
   ```
3. Update the backend API configuration:
   Open `public/config.js` (or `dist/config.js` if already built) and change the `API_URL` to point to your PHP backend:
   ```javascript
   window.PORTAL_CONFIG = {
       API_URL: "http://your-server-ip/php-service/api_satusehat_portal.php"
   };
   ```

### 3. Running for Development
1. **Start the Frontend:**
   ```bash
   npm run dev
   ```
   *The React app will be accessible at `http://localhost:5173`.*

2. **Start the Backend:**
   You can serve the PHP directory natively with Apache/Nginx, or use PHP's built-in server:
   ```bash
   cd ../php-service
   php -S 127.0.0.1:8080
   ```

### 4. Deploying to Production
1. Build the React app:
   ```bash
   npm run build
   ```
2. The compiled static files will be generated in the `dist` folder.
3. You can copy the contents of `dist` to a web-accessible directory in your `SIMRS-Khanza-fork/webapps` folder, or serve it directly via Nginx/Apache.

---

## 📖 End-to-End User Guide

### Step 1: Login
Access the portal. You will be greeted with the Login screen. 
Enter the credentials of an active SIMRS Khanza `user`. The system securely decrypts and verifies this against the local database.

### Step 2: Patient Search
Once logged in, you enter the Dashboard. You have three search tabs:
- **No Rekam Medis (RM):** The quickest way for local patients. It will display their Name, NIK, Birthdate, and whether they have an **IHS Number** mapped. If they don't, a quick button allows you to jump to a Satu Sehat search.
- **NIK:** Directly hits the Satu Sehat API to find an adult patient. 
- **NIK Ibu (Bayi):** Used for newborns. Requires the Mother's NIK and the Baby's Date of Birth.

### Step 3: View & Synchronize
If you searched by NIK or NIK Ibu and the patient exists in Satu Sehat, their data is displayed on screen, and **their IHS number is automatically synchronized** to your SIMRS DB behind the scenes!

### Step 4: Create Patient
If a search on Satu Sehat yields no results, the application will highlight this and offer a "Yes, Create Patient" button. Pressing this will formulate a FHIR R4 standard payload and register the patient in Satu Sehat. Once created, the new IHS number is immediately bound to your local database.
