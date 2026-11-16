-- =====================================================================
--  MyDentist Dental Clinic - Web-Based Dental Appointment System
--  MySQL / MariaDB schema (XAMPP compatible)
--
--  Easiest setup: open http://localhost/mydentist/install.php
--  Manual setup:  import this file in phpMyAdmin (only if you use MySQL instead of SQL Server) (it creates the
--                 `mydentist` database, all tables and default data).
-- =====================================================================

CREATE DATABASE IF NOT EXISTS mydentist CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mydentist;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS schedule_blocks;
DROP TABLE IF EXISTS appointments;
DROP TABLE IF EXISTS services;
DROP TABLE IF EXISTS dentists;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS settings;
SET FOREIGN_KEY_CHECKS = 1;

-- Accounts: patients, clinic staff and administrators (role-based access)
CREATE TABLE users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role          VARCHAR(10)  NOT NULL DEFAULT 'patient',   -- patient | staff | admin
    full_name     VARCHAR(120) NOT NULL,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    email         VARCHAR(150) NOT NULL UNIQUE,
    phone         VARCHAR(30)  NULL,
    password_hash VARCHAR(255) NOT NULL,                     -- bcrypt via password_hash()
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    avatar        VARCHAR(100) NULL,                         -- profile picture file name
    last_login_at DATETIME     NULL,
    created_at    DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE dentists (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120) NOT NULL,
    specialty  VARCHAR(120) NULL,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dental services; duration drives how long a booking occupies the schedule
CREATE TABLE services (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(120)  NOT NULL,
    description  VARCHAR(255)  NULL,
    category     VARCHAR(60)   NOT NULL DEFAULT 'General',   -- section of the price list
    duration_min INT UNSIGNED  NOT NULL DEFAULT 30,
    price        DECIMAL(10,2) NOT NULL DEFAULT 0,
    price_max    DECIMAL(10,2) NULL,                         -- highest price when the tariff gives a range
    bookable     TINYINT(1)    NOT NULL DEFAULT 1,           -- 0 = add-on charge, not booked on its own
    icon         VARCHAR(40)   NOT NULL DEFAULT 'bi-heart-pulse',
    is_active    TINYINT(1)    NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE appointments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(20)  NOT NULL UNIQUE,            -- reference no. encoded in the QR code
    manage_token    VARCHAR(64)  NOT NULL,                   -- secret for guest manage links
    patient_id      INT UNSIGNED NULL,                       -- NULL for guest / walk-in
    contact_name    VARCHAR(120) NOT NULL,
    contact_email   VARCHAR(150) NULL,
    contact_phone   VARCHAR(30)  NULL,
    service_id      INT UNSIGNED NOT NULL,
    dentist_id      INT UNSIGNED NOT NULL,
    start_at        DATETIME     NOT NULL,
    end_at          DATETIME     NOT NULL,
    status          VARCHAR(12)  NOT NULL DEFAULT 'pending', -- pending|confirmed|completed|cancelled|no_show
    source          VARCHAR(12)  NOT NULL DEFAULT 'online',  -- online|guest|walk_in|emergency
    is_emergency    TINYINT(1)   NOT NULL DEFAULT 0,
    patient_notes   TEXT         NULL,
    staff_notes     TEXT         NULL,
    reminder_24_sent TINYINT(1)  NOT NULL DEFAULT 0,
    reminder_1_sent  TINYINT(1)  NOT NULL DEFAULT 0,
    reminder_12_sent TINYINT(1)  NOT NULL DEFAULT 0,
    reminder_6_sent  TINYINT(1)  NOT NULL DEFAULT 0,
    checked_in_at   DATETIME     NULL,
    extra_services  VARCHAR(100) NULL,                           -- more services in the same visit, e.g. ,3,5,
    is_demo         TINYINT(1)   NOT NULL DEFAULT 0,          -- demo/sample record
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NOT NULL,
    INDEX idx_appt_time (start_at, end_at),
    INDEX idx_appt_status (status),
    INDEX idx_appt_dentist (dentist_id, start_at),
    CONSTRAINT fk_appt_patient FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_appt_service FOREIGN KEY (service_id) REFERENCES services(id),
    CONSTRAINT fk_appt_dentist FOREIGN KEY (dentist_id) REFERENCES dentists(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Blocked time (holidays, breaks, dentist unavailable). dentist_id NULL = whole clinic
CREATE TABLE schedule_blocks (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dentist_id INT UNSIGNED NULL,
    start_at   DATETIME     NOT NULL,
    end_at     DATETIME     NOT NULL,
    reason     VARCHAR(150) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME     NOT NULL,
    INDEX idx_block_time (start_at, end_at),
    CONSTRAINT fk_block_dentist FOREIGN KEY (dentist_id) REFERENCES dentists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Accountability trail of administrative actions
CREATE TABLE audit_logs (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NULL,
    action         VARCHAR(60)  NOT NULL,
    details        TEXT         NULL,
    appointment_id INT UNSIGNED NULL,
    ip_address     VARCHAR(45)  NULL,
    created_at     DATETIME     NOT NULL,
    INDEX idx_audit_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Key/value store for system state (last cron run, last backup, ...)
CREATE TABLE settings (
    skey   VARCHAR(60) PRIMARY KEY,
    svalue TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
--  Default data
--  admin / Admin@123   staff / Staff@123   maria / Patient@123
--  CHANGE THESE PASSWORDS after the first login (Settings page).
-- ---------------------------------------------------------------------
INSERT INTO users (role, full_name, username, email, phone, password_hash, created_at) VALUES
('admin',   'Dr. Keith Warren Isidro', 'admin', 'admin@mydentist.local', '+63 900 000 0001', '$2y$10$1pVyyvpZM/VrOD0ax6lEEehU9z1bcW.i293Y1s7y07q1Efj8ucEjW', NOW()),
('staff',   'Front Desk Staff',        'staff', 'staff@mydentist.local', '+63 900 000 0002', '$2y$10$x2jHyUdrGqFAzIEF58CekegE./nzdB1omDMEeZzq/Cuc5WCpIBysu', NOW()),
('patient', 'Maria Santos',            'maria', 'maria@example.com',     '+63 917 123 4567', '$2y$10$acfKq16SOJnLLdoz.FLfhubtV9dvE6hCoc96tiVPSq0M18btTZ/E6', NOW());

INSERT INTO dentists (name, specialty) VALUES
('Dr. Keith Warren Isidro', 'General & Cosmetic Dentistry');

INSERT INTO settings (skey, svalue) VALUES ('schema_version', '6');

-- Services and rates: Philippine Dental Association - Bukidnon Chapter, Approved Dental Tariff (2023).
-- Generated from includes/tariff.php. Durations are estimates; adjust them in Admin > Services & Dentists.
INSERT INTO services (name, description, category, duration_min, price, price_max, bookable, icon) VALUES
('Consultation', 'Oral examination and consultation', 'General', 30, 500, NULL, 1, 'bi-clipboard2-pulse'),
('Dental Certificate', 'Issuance of a dental certificate', 'General', 30, 500, NULL, 1, 'bi-clipboard2-pulse'),
('Fluoride Treatment / Fluoride Varnish with Oral Prophylaxis', 'Cleaning with fluoride application', 'General', 60, 1700, NULL, 1, 'bi-clipboard2-pulse'),
('Hypersensitivity Treatment (per tooth)', 'Treatment of sensitive teeth', 'General', 30, 500, NULL, 1, 'bi-clipboard2-pulse'),
('Chairside Bleaching', 'In-clinic teeth whitening', 'Bleaching', 90, 11000, 16000, 1, 'bi-brightness-high'),
('Take-Home Bleaching with Tray', 'Whitening kit with custom tray', 'Bleaching', 45, 15000, NULL, 1, 'bi-brightness-high'),
('Periapical Radiography', 'Single-tooth X-ray', 'Radiography', 30, 600, NULL, 1, 'bi-shield-plus'),
('Panoramic X-ray', 'Full-mouth panoramic X-ray', 'Radiography', 30, 1000, NULL, 1, 'bi-shield-plus'),
('Cephalometric X-ray', 'Side-view skull X-ray', 'Radiography', 30, 1000, NULL, 1, 'bi-shield-plus'),
('Simple / Normal Scaling', 'Routine cleaning: scaling and polishing', 'Oral Prophylaxis', 45, 1300, 1500, 1, 'bi-stars'),
('Heavy / Nicotine Stain Removal', 'Cleaning of heavy deposits and stains', 'Oral Prophylaxis', 60, 2000, NULL, 1, 'bi-stars'),
('Deep Scaling with Root Planing (per quadrant)', 'Deep cleaning below the gum line', 'Oral Prophylaxis', 60, 2000, NULL, 1, 'bi-stars'),
('Tooth Extraction - Anterior, Pedo', 'Front tooth removal for children', 'Oral Surgery', 30, 700, NULL, 1, 'bi-scissors'),
('Tooth Extraction - Anterior, Adult', 'Front tooth removal for adults', 'Oral Surgery', 45, 1000, NULL, 1, 'bi-scissors'),
('Tooth Extraction - Posterior, Pedo', 'Back tooth removal for children', 'Oral Surgery', 30, 900, NULL, 1, 'bi-scissors'),
('Tooth Extraction - Posterior, Adult', 'Back tooth removal for adults', 'Oral Surgery', 45, 1000, NULL, 1, 'bi-scissors'),
('Complicated Extraction (additional)', 'Added to an extraction when complicated', 'Oral Surgery', 0, 500, NULL, 0, 'bi-scissors'),
('Additional Anesthetic (per carpule)', 'Added per extra carpule of anesthetic', 'Oral Surgery', 0, 300, NULL, 0, 'bi-scissors'),
('Odontectomy - Impacted 3rd Molar', 'Surgical removal of an impacted wisdom tooth', 'Odontectomy and Surgery', 90, 10000, 15000, 1, 'bi-bandaid'),
('Odontectomy - Impacted Pre-molar', 'Surgical removal of an impacted pre-molar', 'Odontectomy and Surgery', 90, 8000, 10000, 1, 'bi-bandaid'),
('Odontectomy - Impacted Canine', 'Surgical removal of an impacted canine', 'Odontectomy and Surgery', 120, 12500, 16000, 1, 'bi-bandaid'),
('Odontectomy - Class I Impaction', 'Surgical removal, Class I impaction', 'Odontectomy and Surgery', 60, 5000, 8000, 1, 'bi-bandaid'),
('Canine Exposure with Button', 'Exposure of an impacted canine with button', 'Odontectomy and Surgery', 60, 2500, 4000, 1, 'bi-bandaid'),
('Apicoectomy', 'Root-end surgery', 'Odontectomy and Surgery', 90, 7000, NULL, 1, 'bi-bandaid'),
('Apicoectomy with RCT', 'Root-end surgery with root canal treatment', 'Odontectomy and Surgery', 120, 11000, NULL, 1, 'bi-bandaid'),
('Gingivectomy (per quadrant)', 'Gum tissue removal, per quadrant', 'Odontectomy and Surgery', 60, 4500, NULL, 1, 'bi-bandaid'),
('Gingivectomy (per tooth)', 'Gum tissue removal, per tooth', 'Odontectomy and Surgery', 30, 600, NULL, 1, 'bi-bandaid'),
('Alveolectomy (per quadrant)', 'Bone smoothing, per quadrant', 'Odontectomy and Surgery', 45, 1500, NULL, 1, 'bi-bandaid'),
('Electrocautery (per tooth, additional)', 'Added per tooth', 'Odontectomy and Surgery', 0, 1000, NULL, 0, 'bi-bandaid'),
('Incision and Drainage', 'Drainage of a dental abscess', 'Odontectomy and Surgery', 45, 2800, NULL, 1, 'bi-bandaid'),
('Dental Implant', 'Implant placement', 'Odontectomy and Surgery', 120, 120000, NULL, 1, 'bi-bandaid'),
('Additional Bone Graft', 'Added to an implant when needed', 'Odontectomy and Surgery', 0, 40000, NULL, 0, 'bi-bandaid'),
('Pulpotomy (Pedo)', 'Partial pulp treatment for children', 'Root Canal Treatment', 60, 2800, NULL, 1, 'bi-activity'),
('Pulpectomy / RCT - Anterior (per canal)', 'Root canal treatment, front tooth; radiographs excluded', 'Root Canal Treatment', 90, 8000, NULL, 1, 'bi-activity'),
('Pulpectomy / RCT - Posterior (per canal)', 'Root canal treatment, back tooth; radiographs excluded', 'Root Canal Treatment', 90, 7000, NULL, 1, 'bi-activity'),
('RCT with Fiber Post (additional)', 'Added to a root canal treatment', 'Root Canal Treatment', 0, 2500, NULL, 0, 'bi-activity'),
('RCT with Metal Dowel Post (additional)', 'Added to a root canal treatment', 'Root Canal Treatment', 0, 1500, NULL, 0, 'bi-activity'),
('Pit and Fissure Sealant (per tooth)', 'Protective sealant', 'Fillings / Restoration', 30, 1000, NULL, 1, 'bi-droplet'),
('Temporary Tooth Filling', 'Temporary filling', 'Fillings / Restoration', 30, 700, NULL, 1, 'bi-droplet'),
('Light Cured Composite Filling - Class I', 'Permanent tooth-coloured filling', 'Fillings / Restoration', 45, 1300, NULL, 1, 'bi-droplet'),
('Light Cured Composite Filling - Class II', 'Permanent tooth-coloured filling', 'Fillings / Restoration', 45, 1500, NULL, 1, 'bi-droplet'),
('Additional Tooth Surface (composite)', 'Added per extra tooth surface', 'Fillings / Restoration', 0, 500, NULL, 0, 'bi-droplet'),
('Chemical Cured / Glass Ionomer Filling', 'Permanent filling', 'Fillings / Restoration', 45, 1000, NULL, 1, 'bi-droplet'),
('Diastema Closure (per proximal)', 'Closing a gap between teeth', 'Fillings / Restoration', 60, 2000, NULL, 1, 'bi-droplet'),
('Inlay - Direct', 'Inlay restoration', 'Fillings / Restoration', 60, 3500, 4700, 1, 'bi-droplet'),
('Inlay - Indirect', 'Laboratory-made inlay', 'Fillings / Restoration', 60, 5800, 7000, 1, 'bi-droplet'),
('Onlay - Direct', 'Onlay restoration', 'Fillings / Restoration', 60, 4600, 5800, 1, 'bi-droplet'),
('Onlay - Indirect', 'Laboratory-made onlay', 'Fillings / Restoration', 60, 7000, 8000, 1, 'bi-droplet'),
('Enameloplasty', 'Enamel reshaping', 'Fillings / Restoration', 30, 600, NULL, 1, 'bi-droplet'),
('Temporary Crown', 'Temporary crown', 'Fixed Partial Dentures / Jacket Crowns', 45, 1500, NULL, 1, 'bi-gem'),
('Plastic Jacket Crown', 'Plastic jacket crown', 'Fixed Partial Dentures / Jacket Crowns', 60, 6000, NULL, 1, 'bi-gem'),
('Plastic Jacket Crown with Metal Backing', 'Plastic jacket with metal backing', 'Fixed Partial Dentures / Jacket Crowns', 60, 8000, NULL, 1, 'bi-gem'),
('Composite Crown', 'Composite crown', 'Fixed Partial Dentures / Jacket Crowns', 60, 8000, NULL, 1, 'bi-gem'),
('Ceramage Crown', 'Ceramage crown', 'Fixed Partial Dentures / Jacket Crowns', 60, 9200, 11500, 1, 'bi-gem'),
('Emax Crown', 'Emax all-ceramic crown', 'Fixed Partial Dentures / Jacket Crowns', 60, 20000, NULL, 1, 'bi-gem'),
('Porcelain Anterior - Fused to Metal', 'Front tooth porcelain crown', 'Fixed Partial Dentures / Jacket Crowns', 60, 12000, NULL, 1, 'bi-gem'),
('Porcelain Anterior - Fused to Gold', 'Front tooth porcelain crown', 'Fixed Partial Dentures / Jacket Crowns', 60, 17500, NULL, 1, 'bi-gem'),
('Porcelain Anterior - Tilite/Titanium', 'Front tooth porcelain crown', 'Fixed Partial Dentures / Jacket Crowns', 60, 20000, NULL, 1, 'bi-gem'),
('Porcelain Anterior - All Porcelain', 'Front tooth porcelain crown', 'Fixed Partial Dentures / Jacket Crowns', 60, 20000, NULL, 1, 'bi-gem'),
('Porcelain Posterior - Fused to Metal', 'Back tooth porcelain crown', 'Fixed Partial Dentures / Jacket Crowns', 60, 15000, NULL, 1, 'bi-gem'),
('Porcelain Posterior - Tilite/Titanium', 'Back tooth porcelain crown', 'Fixed Partial Dentures / Jacket Crowns', 60, 25000, NULL, 1, 'bi-gem'),
('Porcelain Posterior - All Porcelain', 'Back tooth porcelain crown', 'Fixed Partial Dentures / Jacket Crowns', 60, 25000, NULL, 1, 'bi-gem'),
('Zirconia Crown - Anterior', 'Front tooth zirconia crown', 'Fixed Partial Dentures / Jacket Crowns', 60, 30000, NULL, 1, 'bi-gem'),
('Zirconia Crown - Posterior', 'Back tooth zirconia crown', 'Fixed Partial Dentures / Jacket Crowns', 60, 30000, NULL, 1, 'bi-gem'),
('Maryland Bridge with Fiber Reinforcement - Anterior', 'Bonded bridge, front', 'Fixed Partial Dentures / Jacket Crowns', 60, 15000, NULL, 1, 'bi-gem'),
('Maryland Bridge with Fiber Reinforcement - Posterior', 'Bonded bridge, back', 'Fixed Partial Dentures / Jacket Crowns', 60, 18000, NULL, 1, 'bi-gem'),
('Veneer - Direct', 'Direct veneer', 'Veneers / Laminates', 60, 7000, NULL, 1, 'bi-magic'),
('Veneer - Indirect, Composite', 'Laboratory-made composite veneer', 'Veneers / Laminates', 60, 8000, NULL, 1, 'bi-magic'),
('Veneer - Indirect, Zirconia', 'Laboratory-made zirconia veneer', 'Veneers / Laminates', 60, 18000, 20000, 1, 'bi-magic'),
('Veneer - Indirect, Porcelain', 'Laboratory-made porcelain veneer', 'Veneers / Laminates', 60, 15000, NULL, 1, 'bi-magic'),
('Dowel with Build-up - Casted', 'Cast post and core build-up', 'Dowels and Re-cementation', 60, 3500, NULL, 1, 'bi-capsule'),
('Dowel with Build-up - Fiber Ribbond', 'Fiber post and core build-up', 'Dowels and Re-cementation', 60, 4500, NULL, 1, 'bi-capsule'),
('Re-cementation of Dislodged Crown - Plastic', 'Re-cementing a loose plastic crown', 'Dowels and Re-cementation', 30, 1500, NULL, 1, 'bi-capsule'),
('Re-cementation of Dislodged Crown - Porcelain', 'Re-cementing a loose porcelain crown', 'Dowels and Re-cementation', 30, 2000, NULL, 1, 'bi-capsule'),
('Complete Denture - New Ace/Leeformatron/New Ace PX (plastic)', 'Plastic teeth', 'Complete Denture', 60, 15000, NULL, 1, 'bi-emoji-smile'),
('Complete Denture - New Ace/Leeformatron/New Ace PX (plastic), 1 arch', 'Plastic teeth, one arch', 'Complete Denture', 60, 8000, NULL, 1, 'bi-emoji-smile'),
('Complete Denture - Naturatone/Monalisa (semi porcelain)', 'Semi-porcelain teeth', 'Complete Denture', 60, 20000, NULL, 1, 'bi-emoji-smile'),
('Complete Denture - Naturatone/Monalisa (semi porcelain), 1 arch', 'Semi-porcelain teeth, one arch', 'Complete Denture', 60, 11000, NULL, 1, 'bi-emoji-smile'),
('Complete Denture - Porcelain/Naturadent', 'Porcelain teeth', 'Complete Denture', 60, 25000, NULL, 1, 'bi-emoji-smile'),
('Complete Denture - Porcelain/Naturadent, 1 arch', 'Porcelain teeth, one arch', 'Complete Denture', 60, 13000, NULL, 1, 'bi-emoji-smile'),
('Complete Denture - Ivocap/Vertex Quint', 'Ivocap / Vertex Quint', 'Complete Denture', 60, 45000, NULL, 1, 'bi-emoji-smile'),
('Complete Denture - Ivocap/Vertex Quint, 1 arch', 'Ivocap / Vertex Quint, one arch', 'Complete Denture', 60, 25000, NULL, 1, 'bi-emoji-smile'),
('Gold Mesh or Unbreakable (additional)', 'Added to a complete denture', 'Complete Denture', 0, 2000, NULL, 0, 'bi-emoji-smile'),
('Removable Partial Denture - Duracryl/Acrylic/Spring Plate', 'Acrylic partial denture base', 'Removable Partial Denture', 60, 3500, NULL, 1, 'bi-heart-pulse'),
('New Ace/Leeformatron Pontic (additional, per pontic)', 'Added per pontic', 'Removable Partial Denture', 0, 1500, NULL, 0, 'bi-heart-pulse'),
('Naturatone/Monalisa Pontic (additional, per pontic)', 'Added per pontic', 'Removable Partial Denture', 0, 3000, NULL, 0, 'bi-heart-pulse'),
('Porcelain/Naturadent Pontic (additional, per pontic)', 'Added per pontic', 'Removable Partial Denture', 0, 3500, NULL, 0, 'bi-heart-pulse'),
('Wire Clasp (additional)', 'Added to a partial denture', 'Removable Partial Denture', 0, 1000, NULL, 0, 'bi-heart-pulse'),
('Casted Clasp (additional)', 'Added to a partial denture', 'Removable Partial Denture', 0, 2000, NULL, 0, 'bi-heart-pulse'),
('One-Piece Casting (casing only) - Saddle', 'Cast metal framework', 'Removable Partial Denture', 60, 7500, NULL, 1, 'bi-heart-pulse'),
('One-Piece Casting (casing only) - Unilateral', 'Cast metal framework', 'Removable Partial Denture', 60, 7500, NULL, 1, 'bi-heart-pulse'),
('One-Piece Casting (casing only) - Bilateral', 'Cast metal framework', 'Removable Partial Denture', 60, 10000, NULL, 1, 'bi-heart-pulse'),
('One-Piece Casting (casing only) - Butterfly', 'Cast metal framework', 'Removable Partial Denture', 60, 8000, NULL, 1, 'bi-heart-pulse'),
('One-Piece Casting (casing only) - Full Palate', 'Cast metal framework', 'Removable Partial Denture', 60, 12000, NULL, 1, 'bi-heart-pulse'),
('Valplast/Flexident (casing only) - Bilateral Base', 'Flexible partial denture', 'Removable Partial Denture', 60, 17500, NULL, 1, 'bi-heart-pulse'),
('Valplast/Flexident (casing only) - Unilateral Base', 'Flexible partial denture', 'Removable Partial Denture', 60, 11500, NULL, 1, 'bi-heart-pulse'),
('Denture Repair - Cracks', 'Repair of a cracked denture', 'Denture Repairs', 30, 1500, NULL, 1, 'bi-sliders'),
('Denture Repair - Double Crack', 'Repair of a double crack', 'Denture Repairs', 30, 2500, NULL, 1, 'bi-sliders'),
('Denture Repair - Separated', 'Repair of a separated denture', 'Denture Repairs', 30, 2000, NULL, 1, 'bi-sliders'),
('Pontic Re-attachment - Direct', 'Re-attaching a denture tooth', 'Denture Repairs', 30, 1000, NULL, 1, 'bi-sliders'),
('Pontic Re-attachment - Indirect', 'Re-attaching a denture tooth', 'Denture Repairs', 30, 1500, NULL, 1, 'bi-sliders'),
('Replace or Add Pontic (per pontic)', 'Replacing or adding a denture tooth', 'Denture Repairs', 30, 1000, NULL, 1, 'bi-sliders'),
('Repair with Impression Taking - Plastic', 'Repair with impression, plastic', 'Denture Repairs', 45, 1500, NULL, 1, 'bi-sliders'),
('Repair with Impression Taking - Semi Porcelain', 'Repair with impression, semi porcelain', 'Denture Repairs', 45, 3000, NULL, 1, 'bi-sliders'),
('Repair with Impression Taking - Porcelain', 'Repair with impression, porcelain', 'Denture Repairs', 45, 3000, NULL, 1, 'bi-sliders'),
('Denture Relining (per arch)', 'Relining for a better fit', 'Denture Repairs', 45, 3000, NULL, 1, 'bi-sliders'),
('Denture Rebasing (per arch)', 'Replacing the denture base', 'Denture Repairs', 45, 5000, NULL, 1, 'bi-sliders'),
('Soft Relining', 'Soft liner', 'Denture Repairs', 45, 3000, NULL, 1, 'bi-sliders'),
('Metal Brackets (conventional) - CL I', 'Conventional metal braces', 'Orthodontics', 90, 65000, NULL, 1, 'bi-sliders'),
('Metal Brackets (conventional) - CL II', 'Conventional metal braces', 'Orthodontics', 90, 75000, NULL, 1, 'bi-sliders'),
('Ceramic Brackets', 'Ceramic braces', 'Orthodontics', 90, 100000, NULL, 1, 'bi-sliders'),
('Self-Ligating Brackets', 'Self-ligating braces', 'Orthodontics', 90, 90000, 100000, 1, 'bi-sliders'),
('Retainers, Hawley''s (upper and lower)', 'Hawley retainers', 'Orthodontics', 45, 10000, NULL, 1, 'bi-sliders'),
('Lingual Retainers (upper and lower)', 'Fixed lingual retainers', 'Orthodontics', 45, 10000, NULL, 1, 'bi-sliders'),
('Invisible Retainer (upper and lower)', 'Clear retainers', 'Orthodontics', 45, 10000, NULL, 1, 'bi-sliders'),
('Expander / Removable Appliance', 'Orthodontic appliance', 'Orthodontics', 45, 10000, NULL, 1, 'bi-sliders'),
('Inclined Plane', 'Inclined plane appliance', 'Orthodontics', 45, 10000, NULL, 1, 'bi-sliders'),
('Mouthguard (per arch)', 'Custom mouthguard', 'Orthodontics', 45, 10000, NULL, 1, 'bi-sliders'),
('Splint', 'Occlusal splint', 'Orthodontics', 60, 25000, 30000, 1, 'bi-sliders'),
('TMJ Equilibration (per session)', 'Bite adjustment session', 'Orthodontics', 60, 2900, NULL, 1, 'bi-sliders'),
('Myobrace / EF Line', 'Myofunctional appliance', 'Orthodontics', 45, 30000, NULL, 1, 'bi-sliders'),
('TADS', 'Temporary anchorage devices', 'Orthodontics', 45, 10000, NULL, 1, 'bi-sliders'),
('Re-cementation of Brackets and Bands', 'Re-cementing loose brackets or bands', 'Orthodontics', 30, 1000, NULL, 1, 'bi-sliders'),
('Replacement of Brackets and Bands', 'Replacing brackets or bands', 'Orthodontics', 30, 1500, NULL, 1, 'bi-sliders');
