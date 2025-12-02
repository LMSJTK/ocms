# Database Support

The Headless PHP Content Platform supports both **PostgreSQL** and **MySQL** databases. You can choose which database to use by setting the `type` parameter in your configuration file.

## Supported Databases

- **PostgreSQL** 12+ (recommended)
- **MySQL** 5.7+ or **MariaDB** 10.3+

## Configuration

Edit `config/config.php` file to specify which database type to use:

```php
'database' => [
    'type' => 'pgsql', // 'pgsql' for PostgreSQL or 'mysql' for MySQL
    'host' => 'localhost',
    'port' => '5432', // 5432 for PostgreSQL, 3306 for MySQL
    'dbname' => 'database_name',
    'username' => 'db_username',
    'password' => 'db_password',
    'schema' => 'global' // PostgreSQL schema (ignored for MySQL)
]
```

## PostgreSQL Setup

### 1. Install PostgreSQL

```bash
# Ubuntu/Debian
sudo apt-get install postgresql postgresql-contrib

# macOS with Homebrew
brew install postgresql
```

### 2. Create Database and Schema

```bash
# Connect to PostgreSQL
sudo -u postgres psql

# Create database
CREATE DATABASE database_name;

# Create user
CREATE USER db_username WITH PASSWORD 'db_password';

# Grant privileges
GRANT ALL PRIVILEGES ON DATABASE database_name TO db_username;

# Connect to the database
\c database_name

# Create schema
CREATE SCHEMA global;

# Grant schema privileges
GRANT ALL ON SCHEMA global TO db_username;
```

### 3. Load Schema

```bash
psql -U db_username -d database_name -f database/schema.sql
```

### 4. Optional: Run Migrations

If you're upgrading from an earlier version, run these migrations:

```bash
# Add tags field to content table
psql -U db_username -d database_name -f database/migration_add_tags_field.sql

# Add thumbnail support to content table
psql -U db_username -d database_name -f database/migration_add_thumbnail.sql
```

**Note**: If you're doing a fresh install using the schema.sql file, you don't need to run migrations - all fields are already included.

## MySQL Setup

### 1. Install MySQL

```bash
# Ubuntu/Debian
sudo apt-get install mysql-server

# macOS with Homebrew
brew install mysql
```

### 2. Create Database and User

```bash
# Connect to MySQL
mysql -u root -p

# Create database
CREATE DATABASE database_name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

# Create user
CREATE USER 'db_username'@'localhost' IDENTIFIED BY 'db_password';

# Grant privileges
GRANT ALL PRIVILEGES ON database_name.* TO 'db_username'@'localhost';

# Flush privileges
FLUSH PRIVILEGES;
```

### 3. Load Schema

```bash
mysql -u db_username -p database_name < database/schema.mysql.sql
```

### 4. Optional: Run Migrations

If you're upgrading from an earlier version, run these migrations:

```bash
# Add tags field to content table
mysql -u db_username -p database_name < database/migration_add_tags_field.mysql.sql

# Add thumbnail support to content table
mysql -u db_username -p database_name < database/migration_add_thumbnail.mysql.sql
```

**Note**: If you're doing a fresh install using the schema.mysql.sql file, you don't need to run migrations - all fields are already included.

## PHP Extensions

Make sure you have the appropriate PHP PDO extension installed:

### For PostgreSQL:
```bash
# Ubuntu/Debian
sudo apt-get install php-pgsql

# macOS with Homebrew
brew install php
# PostgreSQL support is usually included
```

### For MySQL:
```bash
# Ubuntu/Debian
sudo apt-get install php-mysql

# macOS with Homebrew
brew install php
# MySQL support is usually included
```

## Verifying Setup

After configuration, visit `http://DOMAIN/public/system-check.php` to verify:
- Database connection is successful
- Required tables are created
- Proper PHP extensions are loaded

## Key Differences Between PostgreSQL and MySQL

### Data Types
| Feature | PostgreSQL | MySQL |
|---------|-----------|-------|
| Auto-increment | `SERIAL` | `AUTO_INCREMENT` |
| Binary data | `bytea` | `LONGBLOB` |
| JSON data | `jsonb` | `JSON` |
| Text data | `text` | `TEXT` / `VARCHAR` |

### Schema Support
- **PostgreSQL**: Uses schemas (e.g., `global.content`)
- **MySQL**: No schema concept; uses database namespaces directly

### Boolean Values
- **PostgreSQL**: Uses `true`/`false` keywords
- **MySQL**: Uses `1`/`0` integers (handled automatically by the Database class)

### String Aggregation
- **PostgreSQL**: `string_agg(column, separator)`
- **MySQL**: `GROUP_CONCAT(column SEPARATOR separator)`

### Timestamp Functions
- Both support `NOW()` and `CURRENT_TIMESTAMP`

## Migration Between Databases

The application abstracts most database differences, but if you need to migrate data:

1. Export data from current database
2. Update `config/config.php` with new database type and credentials
3. Load the appropriate schema file for new database
4. Import data using database-specific tools

Note: You may need to adjust any custom queries that use database-specific features.

## Troubleshooting

### Connection Failed
- Verify database credentials in `config/config.php`
- Check that the database server is running
- Ensure firewall allows connections (default ports: PostgreSQL 5432, MySQL 3306)
- Verify the correct PHP PDO extension is installed

### Tables Not Found
- Make sure you loaded the correct schema file (`schema.sql` for PostgreSQL, `schema.mysql.sql` for MySQL)
- For PostgreSQL, verify the schema name matches configuration
- Check user privileges on the database

### Query Errors
- Ensure you're using the correct schema file for database type
- Check that all migrations have been run
- Review error logs for database-specific syntax issues

## Performance Considerations

Both databases perform well for this application, but there are some considerations:

- **PostgreSQL**: Better for complex queries, full-text search, and JSON operations
- **MySQL**: Slightly faster for simple read-heavy workloads

For most use cases, either database will work excellently. Choose based on existing infrastructure and team expertise.
