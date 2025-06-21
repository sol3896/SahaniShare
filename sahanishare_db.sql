-- sahanishare_db.sql

-- Create the database if it doesn't exist
CREATE DATABASE IF NOT EXISTS sahanishare_db;

-- Use the newly created database
USE sahanishare_db;

-- Table for Users (Donors, Recipients, Admins, Moderators)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL, -- Store hashed passwords, NEVER plain text
    organization_name VARCHAR(255) NOT NULL,
    role ENUM('donor', 'recipient', 'admin', 'moderator') NOT NULL,
    status ENUM('pending', 'active', 'inactive', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Table for Food Donations
CREATE TABLE IF NOT EXISTS donations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    donor_id INT NOT NULL,
    description TEXT NOT NULL,
    category VARCHAR(100), -- e.g., 'Produce', 'Baked Goods', 'Prepared Meals'
    quantity DECIMAL(10, 2) NOT NULL,
    unit VARCHAR(50) NOT NULL, -- e.g., 'kgs', 'liters', 'pieces', 'servings'
    expiry_time DATETIME NOT NULL,
    pickup_location TEXT NOT NULL,
    photo_url VARCHAR(255), -- URL to stored image (optional)
    status ENUM('pending', 'approved', 'rejected', 'fulfilled') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (donor_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Table for Recipient Requests for Donations
CREATE TABLE IF NOT EXISTS requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    donation_id INT NOT NULL,
    recipient_id INT NOT NULL,
    requested_quantity DECIMAL(10, 2), -- Can be less than or equal to donation quantity
    status ENUM('pending', 'approved', 'rejected', 'collected') DEFAULT 'pending',
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (donation_id) REFERENCES donations(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Table for Recipient Feedback on Donations
CREATE TABLE IF NOT EXISTS feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    donation_id INT NOT NULL,
    recipient_id INT NOT NULL,
    rating INT CHECK (rating >= 1 AND rating <= 5), -- Star rating 1-5
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (donation_id) REFERENCES donations(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Optional: Indexing for faster lookups
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_donations_donor_id ON donations(donor_id);
CREATE INDEX idx_donations_status ON donations(status);
CREATE INDEX idx_donations_expiry_time ON donations(expiry_time);
CREATE INDEX idx_requests_donation_id ON requests(donation_id);
CREATE INDEX idx_requests_recipient_id ON requests(recipient_id);
