CREATE DATABASE IF NOT EXISTS inventory_system;
USE inventory_system;

SET FOREIGN_KEY_CHECKS=0;
DROP TRIGGER IF EXISTS trg_items_ai;
DROP TRIGGER IF EXISTS trg_items_au;
DROP TRIGGER IF EXISTS trg_items_bd;
DROP TRIGGER IF EXISTS trg_inventory_ai;
DROP TRIGGER IF EXISTS trg_inventory_au;
DROP TRIGGER IF EXISTS trg_inventory_ad;
SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  username VARCHAR(80) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('ADMIN','USER') NOT NULL DEFAULT 'USER',
  position VARCHAR(120) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS warehouses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  warehouse_code VARCHAR(30) NOT NULL UNIQUE,
  warehouse_name VARCHAR(100) NOT NULL,
  location VARCHAR(150) DEFAULT '',
  manager VARCHAR(100) DEFAULT '',
  contact_number VARCHAR(50) DEFAULT '',
  status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_name VARCHAR(100) NOT NULL UNIQUE,
  status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS raw_materials (
  id INT PRIMARY KEY,
  item_code VARCHAR(50) NOT NULL UNIQUE,
  item_name VARCHAR(150) NOT NULL,
  brand VARCHAR(100) DEFAULT '',
  supplier_name VARCHAR(150) DEFAULT '',
  category_id INT NOT NULL,
  unit VARCHAR(30) NOT NULL,
  description TEXT,
  reorder_level INT NOT NULL DEFAULT 0,
  status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_raw_category FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS finished_goods (
  fg_id INT PRIMARY KEY,
  item_code VARCHAR(50) NOT NULL UNIQUE,
  item_name VARCHAR(150) NOT NULL,
  brand VARCHAR(100) DEFAULT '',
  size VARCHAR(30) DEFAULT '',
  category_id INT NOT NULL,
  unit VARCHAR(30) NOT NULL,
  description TEXT,
  reorder_level INT NOT NULL DEFAULT 0,
  status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  quantity INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_fg_category FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_code VARCHAR(50) NOT NULL UNIQUE,
  item_name VARCHAR(150) NOT NULL,
  brand VARCHAR(100) DEFAULT '',
  supplier_name VARCHAR(150) DEFAULT '',
  size VARCHAR(30) DEFAULT '',
  item_type ENUM('Raw Material','Finished Good') NOT NULL,
  category_id INT NOT NULL,
  unit VARCHAR(30) NOT NULL,
  description TEXT,
  reorder_level INT NOT NULL DEFAULT 0,
  status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_items_category FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  warehouse_id INT NOT NULL,
  quantity INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_inventory_item_warehouse (item_id, warehouse_id),
  CONSTRAINT fk_inventory_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_inventory_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

DELIMITER $$
CREATE TRIGGER trg_items_ai AFTER INSERT ON items FOR EACH ROW
BEGIN
  IF NEW.item_type='Raw Material' THEN
    INSERT IGNORE INTO raw_materials(id,item_code,item_name,brand,supplier_name,category_id,unit,description,reorder_level,status,created_at)
    VALUES(NEW.id,NEW.item_code,NEW.item_name,NEW.brand,NEW.supplier_name,NEW.category_id,NEW.unit,NEW.description,NEW.reorder_level,NEW.status,NEW.created_at);
  ELSE
    INSERT IGNORE INTO finished_goods(fg_id,item_code,item_name,brand,size,category_id,unit,description,reorder_level,status,created_at,quantity)
    VALUES(NEW.id,NEW.item_code,NEW.item_name,NEW.brand,NEW.size,NEW.category_id,NEW.unit,NEW.description,NEW.reorder_level,NEW.status,NEW.created_at,0);
  END IF;
END$$
CREATE TRIGGER trg_items_au AFTER UPDATE ON items FOR EACH ROW
BEGIN
  DELETE FROM raw_materials WHERE id=OLD.id;
  DELETE FROM finished_goods WHERE fg_id=OLD.id;
  IF NEW.item_type='Raw Material' THEN
    INSERT IGNORE INTO raw_materials(id,item_code,item_name,brand,supplier_name,category_id,unit,description,reorder_level,status,created_at)
    VALUES(NEW.id,NEW.item_code,NEW.item_name,NEW.brand,NEW.supplier_name,NEW.category_id,NEW.unit,NEW.description,NEW.reorder_level,NEW.status,NEW.created_at);
  ELSE
    INSERT IGNORE INTO finished_goods(fg_id,item_code,item_name,brand,size,category_id,unit,description,reorder_level,status,created_at,quantity)
    VALUES(NEW.id,NEW.item_code,NEW.item_name,NEW.brand,NEW.size,NEW.category_id,NEW.unit,NEW.description,NEW.reorder_level,NEW.status,NEW.created_at,COALESCE((SELECT SUM(quantity) FROM inventory WHERE item_id=NEW.id),0));
  END IF;
END$$
CREATE TRIGGER trg_items_bd BEFORE DELETE ON items FOR EACH ROW
BEGIN
  DELETE FROM raw_materials WHERE id=OLD.id;
  DELETE FROM finished_goods WHERE fg_id=OLD.id;
END$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER trg_inventory_ai AFTER INSERT ON inventory FOR EACH ROW
BEGIN
  UPDATE finished_goods SET quantity=(SELECT COALESCE(SUM(inv.quantity),0) FROM inventory inv WHERE inv.item_id=NEW.item_id) WHERE fg_id=NEW.item_id;
END$$
CREATE TRIGGER trg_inventory_au AFTER UPDATE ON inventory FOR EACH ROW
BEGIN
  UPDATE finished_goods SET quantity=(SELECT COALESCE(SUM(inv.quantity),0) FROM inventory inv WHERE inv.item_id=OLD.item_id) WHERE fg_id=OLD.item_id;
  IF NEW.item_id<>OLD.item_id THEN
    UPDATE finished_goods SET quantity=(SELECT COALESCE(SUM(inv.quantity),0) FROM inventory inv WHERE inv.item_id=NEW.item_id) WHERE fg_id=NEW.item_id;
  END IF;
END$$
CREATE TRIGGER trg_inventory_ad AFTER DELETE ON inventory FOR EACH ROW
BEGIN
  UPDATE finished_goods SET quantity=(SELECT COALESCE(SUM(inv.quantity),0) FROM inventory inv WHERE inv.item_id=OLD.item_id) WHERE fg_id=OLD.item_id;
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL,
  message VARCHAR(500) NOT NULL,
  type VARCHAR(50) NOT NULL DEFAULT 'transaction',
  actor_user_id INT NULL,
  recipient_user_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notifications_created (created_at),
  CONSTRAINT fk_notification_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notification_reads (
  notification_id INT NOT NULL,
  user_id INT NOT NULL,
  read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (notification_id,user_id),
  CONSTRAINT fk_notification_read_notification FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
  CONSTRAINT fk_notification_read_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

SET @idx_count := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND INDEX_NAME='idx_notifications_recipient');
SET @idx_sql := IF(@idx_count=0,'CREATE INDEX idx_notifications_recipient ON notifications(recipient_user_id,created_at)','SELECT 1');
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;

CREATE TABLE IF NOT EXISTS stock_in (
  id INT AUTO_INCREMENT PRIMARY KEY,
  stock_in_number VARCHAR(50) NOT NULL UNIQUE,
  item_id INT NOT NULL,
  warehouse_id INT NOT NULL,
  quantity INT NOT NULL,
  unit VARCHAR(30) NOT NULL DEFAULT '',
  supplier VARCHAR(150) DEFAULT '',
  reference_number VARCHAR(100) DEFAULT '',
  remarks TEXT,
  date DATE NOT NULL,
  user_id INT NULL,
  CONSTRAINT fk_stockin_item FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT fk_stockin_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
  CONSTRAINT fk_stockin_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_out (
  id INT AUTO_INCREMENT PRIMARY KEY,
  stock_out_number VARCHAR(50) NOT NULL UNIQUE,
  item_id INT NOT NULL,
  warehouse_id INT NOT NULL,
  quantity INT NOT NULL,
  purpose VARCHAR(150) DEFAULT '',
  destination ENUM('Sales','Production','Procurement') NULL DEFAULT NULL,
  reference_number VARCHAR(100) DEFAULT '',
  remarks TEXT,
  date DATE NOT NULL,
  user_id INT NULL,
  CONSTRAINT fk_stockout_item FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT fk_stockout_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
  CONSTRAINT fk_stockout_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_transfers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transfer_number VARCHAR(50) NOT NULL UNIQUE,
  item_id INT NOT NULL,
  from_warehouse INT NOT NULL,
  to_warehouse INT NOT NULL,
  quantity INT NOT NULL,
  reason VARCHAR(150) DEFAULT '',
  remarks TEXT,
  date DATE NOT NULL,
  user_id INT NULL,
  status ENUM('Pending','Completed','Cancelled','Delivered') NOT NULL DEFAULT 'Delivered',
  CONSTRAINT fk_transfer_item FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT fk_transfer_from FOREIGN KEY (from_warehouse) REFERENCES warehouses(id),
  CONSTRAINT fk_transfer_to FOREIGN KEY (to_warehouse) REFERENCES warehouses(id),
  CONSTRAINT fk_transfer_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_transfer_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transfer_id INT NOT NULL,
  item_id INT NOT NULL,
  quantity INT NOT NULL,
  CONSTRAINT fk_transfer_items_transfer FOREIGN KEY (transfer_id) REFERENCES stock_transfers(id) ON DELETE CASCADE,
  CONSTRAINT fk_transfer_items_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_adjustments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  adjustment_number VARCHAR(50) NOT NULL UNIQUE,
  item_id INT NOT NULL,
  warehouse_id INT NOT NULL,
  current_quantity INT NOT NULL DEFAULT 0,
  adjusted_quantity INT NOT NULL DEFAULT 0,
  difference DECIMAL(12,2) NOT NULL DEFAULT 0,
  reason VARCHAR(150) DEFAULT '',
  remarks TEXT,
  date DATE NOT NULL,
  user_id INT NULL,
  CONSTRAINT fk_adjustment_item FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT fk_adjustment_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
  CONSTRAINT fk_adjustment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_movements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  warehouse_id INT NOT NULL,
  movement_type VARCHAR(40) NOT NULL,
  quantity INT NOT NULL,
  unit VARCHAR(30) NULL DEFAULT NULL,
  reference_number VARCHAR(100) DEFAULT '',
  remarks TEXT,
  date DATE NOT NULL,
  user_id INT NULL,
  CONSTRAINT fk_movement_item FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT fk_movement_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
  CONSTRAINT fk_movement_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_number VARCHAR(50) NOT NULL UNIQUE,
  requested_by INT NULL,
  requester_team VARCHAR(50) DEFAULT NULL,
  request_date DATE NOT NULL,
  status ENUM('Pending','Partially Approved','Approved','Disapproved') NOT NULL DEFAULT 'Pending',
  remarks TEXT,
  recipient_name VARCHAR(150) NOT NULL DEFAULT '',
  recipient_gmail VARCHAR(190) NOT NULL DEFAULT '',
  destination ENUM('Production','Sales','Procurement') NULL DEFAULT NULL,
  po_number VARCHAR(100) NOT NULL DEFAULT '',
  expected_receive_date DATE NULL,
  approved_by INT NULL,
  approved_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_request_user FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_request_approved FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS request_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  item_id INT NULL,
  item_name VARCHAR(150) NOT NULL,
  size VARCHAR(30) DEFAULT '',
  warehouse_id INT NOT NULL,
  quantity INT NOT NULL,
  approved_quantity INT NOT NULL DEFAULT 0,
  rejected_quantity INT NOT NULL DEFAULT 0,
  rejection_reason VARCHAR(255) DEFAULT '',
  delivered_quantity INT NOT NULL DEFAULT 0,
  accepted_quantity INT NOT NULL DEFAULT 0,
  inspection_accepted_quantity INT NOT NULL DEFAULT 0,
  inspection_rejected_quantity INT NOT NULL DEFAULT 0,
  inspection_rejection_reason VARCHAR(500) DEFAULT '',
  unit VARCHAR(30) DEFAULT 'pcs',
  CONSTRAINT fk_requestitem_request FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_requestitem_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE SET NULL,
  CONSTRAINT fk_requestitem_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS deliveries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL UNIQUE,
  status ENUM('To Ship','Out for Delivery','Partially Delivered','Delivered','Accepted') NOT NULL DEFAULT 'To Ship',
  expected_days VARCHAR(30) NOT NULL DEFAULT '2-3 days',
  expected_date DATE NULL,
  shipped_date DATE NULL,
  out_for_delivery_date DATE NULL,
  delivered_date DATE NULL,
  received_date DATE NULL,
  remarks TEXT,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_delivery_request FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS delivery_receipts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dr_number VARCHAR(50) NOT NULL UNIQUE,
  delivery_id INT NOT NULL UNIQUE,
  request_id INT NOT NULL,
  receipt_date DATE NOT NULL,
  received_by VARCHAR(150) NOT NULL DEFAULT '',
  received_date DATE NULL,
  remarks TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_dr_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
  CONSTRAINT fk_dr_request FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE
 ) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS supplier_rejections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  delivery_id INT NOT NULL,
  report_number VARCHAR(40) DEFAULT NULL,
  po_number VARCHAR(100) NOT NULL DEFAULT '',
  supplier_name VARCHAR(150) NOT NULL DEFAULT '',
  supplier_gmail VARCHAR(190) NOT NULL DEFAULT '',
  received_date DATE NOT NULL,
  attachment_names TEXT,
  report_status ENUM('Pending','Email Sent','Supplier Response') NOT NULL DEFAULT 'Pending',
  supplier_response TEXT NULL,
  email_sent_at DATETIME NULL,
  supplier_response_at DATETIME NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_supplier_rejection_delivery (delivery_id),
  UNIQUE KEY uq_supplier_rejection_report (report_number),
  CONSTRAINT fk_supplier_rejection_request FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_supplier_rejection_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
  CONSTRAINT fk_supplier_rejection_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS supplier_rejection_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rejection_id INT NOT NULL,
  request_item_id INT NULL,
  item_name VARCHAR(150) NOT NULL,
  ordered_quantity INT NOT NULL DEFAULT 0,
  delivered_quantity INT NOT NULL DEFAULT 0,
  accepted_quantity INT NOT NULL DEFAULT 0,
  rejected_quantity INT NOT NULL DEFAULT 0,
  unit VARCHAR(30) NOT NULL DEFAULT 'pcs',
  rejection_reason VARCHAR(500) DEFAULT '',
  CONSTRAINT fk_sri_rejection FOREIGN KEY (rejection_id) REFERENCES supplier_rejections(id) ON DELETE CASCADE,
  CONSTRAINT fk_sri_request_item FOREIGN KEY (request_item_id) REFERENCES request_items(id) ON DELETE SET NULL
) ENGINE=InnoDB;


INSERT IGNORE INTO users (full_name,email,username,password,role) VALUES
('Admin','admin@inventory.local','admin','$2y$12$JyHQtJmXH9hWrIVVsP3d1uSPoSn62ORz9TAz46idnZPiXw0ReTQYm','ADMIN');
INSERT IGNORE INTO categories (category_name, status) VALUES
('Footwear', 'Active'),
('Shoes', 'Active');

INSERT IGNORE INTO warehouses (warehouse_code, warehouse_name, location, manager, contact_number, status) VALUES
('WH-MAIN', 'Main Warehouse', '', '', '', 'Active'),
('WH-SEC', 'Secondary Warehouse', '', '', '', 'Active');


INSERT IGNORE INTO users (full_name,email,username,password,role) VALUES
('Admin','admin@inventory.local','admin','$2y$12$JyHQtJmXH9hWrIVVsP3d1uSPoSn62ORz9TAz46idnZPiXw0ReTQYm','ADMIN');
INSERT IGNORE INTO categories (category_name, status) VALUES
('Footwear', 'Active'),
('Shoes', 'Active');

INSERT IGNORE INTO warehouses (warehouse_code, warehouse_name, location, manager, contact_number, status) VALUES
('WH-MAIN', 'Main Warehouse', '', '', '', 'Active'),
('WH-SEC', 'Secondary Warehouse', '', '', '', 'Active');

USE inventory_system;
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='requests' AND COLUMN_NAME='requester_team');
SET @q := IF(@c=0,"ALTER TABLE requests ADD COLUMN requester_team VARCHAR(50) DEFAULT NULL AFTER requested_by","SELECT 1");
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='requests' AND COLUMN_NAME='recipient_gmail');
SET @q := IF(@c=0,"ALTER TABLE requests ADD COLUMN recipient_gmail VARCHAR(190) NOT NULL DEFAULT '' AFTER recipient_name","SELECT 1");
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='requests' AND COLUMN_NAME='destination');
SET @q := IF(@c=0,"ALTER TABLE requests ADD COLUMN destination ENUM('Production','Sales','Procurement') NULL DEFAULT NULL AFTER recipient_gmail","SELECT 1");
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

ALTER TABLE requests MODIFY COLUMN status ENUM('Pending','Partially Approved','Approved','Disapproved') NOT NULL DEFAULT 'Pending';
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='request_items' AND COLUMN_NAME='approved_quantity');
SET @q := IF(@c=0,"ALTER TABLE request_items ADD COLUMN approved_quantity INT NOT NULL DEFAULT 0 AFTER quantity","SELECT 1");
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='request_items' AND COLUMN_NAME='rejected_quantity');
SET @q := IF(@c=0,"ALTER TABLE request_items ADD COLUMN rejected_quantity INT NOT NULL DEFAULT 0 AFTER approved_quantity","SELECT 1");
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='request_items' AND COLUMN_NAME='rejection_reason');
SET @q := IF(@c=0,"ALTER TABLE request_items ADD COLUMN rejection_reason VARCHAR(255) DEFAULT '' AFTER rejected_quantity","SELECT 1");
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='request_items' AND COLUMN_NAME='delivered_quantity');
SET @q := IF(@c=0,"ALTER TABLE request_items ADD COLUMN delivered_quantity INT NOT NULL DEFAULT 0 AFTER rejection_reason","SELECT 1");
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='request_items' AND COLUMN_NAME='accepted_quantity');
SET @q := IF(@c=0,"ALTER TABLE request_items ADD COLUMN accepted_quantity INT NOT NULL DEFAULT 0 AFTER delivered_quantity","SELECT 1");
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

UPDATE request_items
SET approved_quantity=quantity
WHERE approved_quantity=0 AND rejected_quantity=0;

UPDATE request_items
SET rejected_quantity=GREATEST(quantity-approved_quantity,0)
WHERE rejected_quantity=0 AND approved_quantity>0 AND approved_quantity<quantity;
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_out' AND COLUMN_NAME='destination');
SET @q := IF(@c=0,"ALTER TABLE stock_out ADD COLUMN destination ENUM('Sales','Production','Procurement') NULL DEFAULT NULL AFTER purpose","SELECT 1");
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND COLUMN_NAME='recipient_user_id');
SET @q := IF(@c=0,"ALTER TABLE notifications ADD COLUMN recipient_user_id INT NULL AFTER actor_user_id","SELECT 1");
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND INDEX_NAME='idx_notifications_recipient');
SET @q := IF(@c=0,"CREATE INDEX idx_notifications_recipient ON notifications(recipient_user_id,created_at)","SELECT 1");
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='finished_goods' AND COLUMN_NAME='quantity');
SET @q := IF(@c=0,"ALTER TABLE finished_goods ADD COLUMN quantity INT NOT NULL DEFAULT 0 AFTER status","SELECT 1");
PREPARE st FROM @q; EXECUTE st; DEALLOCATE PREPARE st;
INSERT INTO users (full_name,email,username,password,role,position) VALUES
('Suficiencia Andrew C','suficiencia@inventory.local','suficiencia','$2y$12$GZC9hXynhQfDzVb0zQ2ur.rMdFlwIw/5zZnlzE78ETOq2PJkZ3skq','ADMIN','Warehouse Manager'),
('Riel Jurado','jurado@inventory.local','jurado','$2y$12$CHgKhdeFHpoZ4U.wrwuPGulr/7u435KxqmGTFGUPKgLSdYYJiUPle','USER','Inventory Staff / Clerk'),
('Banisil, Ferdausi','banisil@inventory.local','banisil','$2y$12$lIv0DE7oqRUsKfuX9rqrKuJxAXm3saEhkhv4vimNRdOYAhhCeYtRC','USER','Warehouse Staff / Associate'),
('Paje Meynard','paje@inventory.local','paje','$2y$12$B3a0yI9qlDV/Yj0CaGdbH.ZbzMcT6yO7ewQbjJ7wzNMnbMfySmHki','ADMIN','Inventory Controller / Analyst'),
('Mendoza Franz Gian T.','mendoza@inventory.local','mendoza','$2y$12$snEPb/7FKtjcxmfMBthY.OV3o5aXrRdIIvkkA0N2s9Uz2Kn0Z0rL.','USER','Picker and Packer'),
('Pompa, Eric Joseph F.','pompa@inventory.local','pompa','$2y$12$kOAIT.ws9rddrw4CPsMMROggmnBKZgy6I2eiBFLerXoUATm/seq7S','USER','Shipping and Receiving Clerk')
ON DUPLICATE KEY UPDATE
  full_name=VALUES(full_name),
  email=VALUES(email),
  password=VALUES(password),
  role=VALUES(role),
  position=VALUES(position);
INSERT IGNORE INTO categories (category_name, status) VALUES
('Footwear', 'Active'),
('Shoes', 'Active');

INSERT IGNORE INTO warehouses (warehouse_code, warehouse_name, location, manager, contact_number, status) VALUES
('WH-MAIN', 'Main Warehouse', '', '', '', 'Active'),
('WH-SEC', 'Secondary Warehouse', '', '', '', 'Active');
DELETE FROM stock_transfer_items;
DELETE FROM stock_transfers;
DELETE FROM stock_adjustments;
DELETE FROM stock_in;
DELETE FROM stock_out;
DELETE FROM stock_movements;
DELETE FROM inventory;
DELETE FROM finished_goods;
DELETE FROM items WHERE item_type='Finished Good';
