CREATE DATABASE IF NOT EXISTS `aviationweather`;

DROP TABLE IF EXISTS metars;
CREATE TABLE IF NOT EXISTS metars (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    raw_text TEXT NOT NULL,
    retrieved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

DROP TABLE IF EXISTS tafs;
CREATE TABLE IF NOT EXISTS tafs (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    icao_id VARCHAR(4) NOT NULL,
    bulletin_time DATETIME NOT NULL,
    issue_time DATETIME NOT NULL,
    valid_time_from TIMESTAMP NOT NULL,
    valid_time_to TIMESTAMP NOT NULL,
    most_recent BOOLEAN NOT NULL,
    remarks VARCHAR(255) NOT NULL,
    lat DECIMAL(10,7) NOT NULL,
    lon DECIMAL(10,7) NOT NULL,
    elevation INT NOT NULL,
    station_name VARCHAR(255) NULL,
    raw_text TEXT NOT NULL,
    retrieved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

DROP TABLE IF EXISTS taf_forecasts;
CREATE TABLE IF NOT EXISTS taf_forecasts (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    taf_id INT NOT NULL,
    time_from TIMESTAMP NOT NULL,
    time_to TIMESTAMP NOT NULL,
    forecast_change VARCHAR(255) NULL,
    probability VARCHAR(255) NULL,
    wind_direction VARCHAR(20) NULL,
    wind_speed INT NULL,
    wind_gust INT NULL,
    wind_shear_height VARCHAR(255) NULL,
    wind_shear_direction INT NULL,
    wind_shear_speed INT NULL,
    visibility VARCHAR(255) NULL,
    altimeter VARCHAR(255) NULL,
    vertical_visibility VARCHAR(255) NULL,
    weather_string VARCHAR(255) NULL,
    not_decoded VARCHAR(255) NULL,
    retrieved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (taf_id) REFERENCES tafs(id) ON DELETE CASCADE
);

DROP TABLE IF EXISTS taf_forecast_clouds;
CREATE TABLE IF NOT EXISTS taf_forecast_clouds (
    taf_forecast_id INT NOT NULL,
    cloud_base INT NULL,
    cloud_cover VARCHAR(20) NULL,
    cloud_type VARCHAR(255) NULL,
    retrieved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (taf_forecast_id) REFERENCES taf_forecasts(id) ON DELETE CASCADE
);

DROP TABLE IF EXISTS error_logs;
CREATE TABLE IF NOT EXISTS error_logs (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    error_message TEXT NOT NULL,
    error_trace TEXT NOT NULL,
    error_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);