CREATE DATABASE IF NOT EXISTS kmk_inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE kmk_inventory;

CREATE TABLE IF NOT EXISTS users (
 id INT AUTO_INCREMENT PRIMARY KEY,
 username VARCHAR(80) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 full_name VARCHAR(150) NOT NULL,
 role ENUM('admin','staff') NOT NULL DEFAULT 'admin',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS academic_years (
 id INT AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(20) NOT NULL UNIQUE,
 is_active TINYINT(1) NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS categories (
 id INT AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL UNIQUE,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS rooms (
 id INT AUTO_INCREMENT PRIMARY KEY,
 category_id INT NOT NULL,
 room_number VARCHAR(40) NOT NULL,
 name VARCHAR(150) NOT NULL,
 notes TEXT NULL,
 access_key_hash VARCHAR(255) NULL,
 access_key_updated_at TIMESTAMP NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_rooms_category FOREIGN KEY(category_id) REFERENCES categories(id)
);

CREATE TABLE IF NOT EXISTS equipment (
 id INT AUTO_INCREMENT PRIMARY KEY,
 academic_year_id INT NOT NULL,
 room_id INT NOT NULL,
 item_number VARCHAR(40) NULL,
 name VARCHAR(200) NOT NULL,
 quantity INT NOT NULL DEFAULT 1,
 inventory_number VARCHAR(100) NULL,
 note TEXT NULL,
 status ENUM('В наличии','Требует ремонта','Списано') NOT NULL DEFAULT 'В наличии',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_equipment_year FOREIGN KEY(academic_year_id) REFERENCES academic_years(id),
 CONSTRAINT fk_equipment_room FOREIGN KEY(room_id) REFERENCES rooms(id),
 INDEX idx_equipment_name(name),
 INDEX idx_inventory_number(inventory_number)
);

CREATE TABLE IF NOT EXISTS movements (
 id INT AUTO_INCREMENT PRIMARY KEY,
 equipment_id INT NOT NULL,
 from_room_id INT NOT NULL,
 to_room_id INT NOT NULL,
 moved_at DATE NOT NULL,
 reason VARCHAR(255) NULL,
 created_by INT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_mov_equipment FOREIGN KEY(equipment_id) REFERENCES equipment(id),
 CONSTRAINT fk_mov_from FOREIGN KEY(from_room_id) REFERENCES rooms(id),
 CONSTRAINT fk_mov_to FOREIGN KEY(to_room_id) REFERENCES rooms(id),
 CONSTRAINT fk_mov_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
);

INSERT IGNORE INTO categories(name) VALUES
('Учебные кабинеты'),('Мастерские и лаборатории'),('Столовая'),('Спортивный зал'),('Общежитие');
INSERT IGNORE INTO academic_years(name,is_active) VALUES ('2026–2027',1),('2025–2026',0);
