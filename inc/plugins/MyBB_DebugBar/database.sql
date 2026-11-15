-- MyBB Debug Bar Plugin Database Schema

-- Create debug log table
CREATE TABLE IF NOT EXISTS {TABLE_PREFIX}mybb_debugbar_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid INT UNSIGNED NOT NULL DEFAULT '0',
  page_url VARCHAR(255) NOT NULL DEFAULT '',
  error_type VARCHAR(50) NOT NULL DEFAULT '',
  error_message TEXT NOT NULL,
  error_file VARCHAR(255) NOT NULL DEFAULT '',
  error_line INT UNSIGNED NOT NULL DEFAULT '0',
  timestamp INT UNSIGNED NOT NULL DEFAULT '0',
  PRIMARY KEY (id),
  KEY uid (uid),
  KEY timestamp (timestamp)
) ENGINE=MyISAM;
