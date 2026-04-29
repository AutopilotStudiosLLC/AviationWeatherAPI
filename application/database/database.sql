CREATE DATABASE IF NOT EXISTS `aviationweather`;

DROP TABLE IF EXISTS stations;
CREATE TABLE IF NOT EXISTS stations (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    icao_id VARCHAR(4) UNIQUE NOT NULL,
    iata_id VARCHAR(3) NULL,
    faa_id VARCHAR(4) NULL,
    wmo_id VARCHAR(10) NULL,
    site_name VARCHAR(255) NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    elevation INT NULL,
    state VARCHAR(2) NULL,
    country VARCHAR(2) NULL,
    priority INT NULL,
    types VARCHAR(255) NULL,
    retrieved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX station_id_index ON stations(icao_id);

DROP TABLE IF EXISTS airport_runways;
DROP TABLE IF EXISTS airports;
CREATE TABLE airports (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    icao_id VARCHAR(4) NOT NULL,
    iata_id VARCHAR(3) NOT NULL,
    faa_id VARCHAR(4) NOT NULL,
    name VARCHAR(255) NOT NULL,
    state VARCHAR(2) NOT NULL,
    country VARCHAR(2) NOT NULL,
    source VARCHAR(255) NOT NULL,
    type VARCHAR(255) NOT NULL,
    lat DECIMAL(10,7) NOT NULL,
    lon DECIMAL(10,7) NOT NULL,
    elevation INT NOT NULL,
    magnetic_declination VARCHAR(10) NULL,
    owner VARCHAR(10) NULL,
    services VARCHAR(10) NULL,
    tower VARCHAR(10) NULL,
    beacon VARCHAR(10) NULL,
    operations VARCHAR(10) NULL,
    frequencies VARCHAR(10) NULL,
    priority VARCHAR(10) NULL,
    retrieved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE airport_runways (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    airport_id INT NOT NULL,
    length INT NOT NULL,
    width INT NOT NULL,
    surface CHAR(1) NOT NULL,
    alignment INT NOT NULL,
    retrieved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (airport_id) REFERENCES airports(id) ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE INDEX airport_id_index ON airport_runways(airport_id);

DROP TABLE IF EXISTS metar_clouds;
DROP TABLE IF EXISTS metars;
CREATE TABLE IF NOT EXISTS metars (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    raw_text TEXT NOT NULL,
    icao_id VARCHAR(4) NOT NULL,
    receipt_time DATETIME NULL,
    report_time DATETIME NOT NULL,
    observation_time DATETIME NOT NULL,
    temperature INT NULL,
    dew_point INT NULL,
    wind_direction INT NULL,
    wind_speed INT NULL,
    visibility VARCHAR(255) NULL,
    altimeter INT NULL,
    sea_level_pressure INT NULL,
    metar_type VARCHAR(255) NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    elevation INT NOT NULL,
    station_name VARCHAR(255) NULL,
    cloud_cover VARCHAR(10) NULL,
    flight_category VARCHAR(10) NULL,
    station_id INT NULL,        # foreign key to "stations" table
    retrieved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS metar_clouds (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    metar_id INT NOT NULL,
    cloud_base INT NULL,
    cloud_cover VARCHAR(20) NULL,
    cloud_type VARCHAR(255) NULL,
    retrieved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (metar_id) REFERENCES metars(id) ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE INDEX metar_id_index ON metar_clouds(metar_id);

DROP TABLE IF EXISTS taf_forecast_clouds;
DROP TABLE IF EXISTS taf_forecasts;
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

    FOREIGN KEY (taf_id) REFERENCES tafs(id) ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE INDEX taf_id_index ON taf_forecasts(taf_id);

CREATE TABLE IF NOT EXISTS taf_forecast_clouds (
    taf_forecast_id INT NOT NULL,
    cloud_base INT NULL,
    cloud_cover VARCHAR(20) NULL,
    cloud_type VARCHAR(255) NULL,
    retrieved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (taf_forecast_id) REFERENCES taf_forecasts(id) ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE INDEX taf_forecast_id_index ON taf_forecast_clouds(taf_forecast_id);

DROP TABLE IF EXISTS error_logs;
CREATE TABLE IF NOT EXISTS error_logs (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    error_message TEXT NOT NULL,
    error_trace TEXT NOT NULL,
    error_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);