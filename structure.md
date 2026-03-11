# DBCommander Backend Structure

src/
  Config/
    ConnectionConfig.php       # JSON config betöltés + validálás
  Driver/
    DriverInterface.php        # közös interface PDO+mysqli felett
    PdoDriver.php
    MysqliDriver.php
    DriverFactory.php          # config alapján példányosít
  Repository/
    DatabaseRepository.php     # SHOW DATABASES
    TableRepository.php        # SHOW TABLES, SHOW CREATE, etc.
    RowRepository.php          # SELECT rows + pagination
    StructureRepository.php    # INFORMATION_SCHEMA queries
  Http/
    Middleware/
      JsonResponseMiddleware.php  # PSR-15: Content-Type + JSON encode
      ErrorHandlerMiddleware.php  # PSR-15: exception → JSON error
    Handler/
      DatabaseListHandler.php     # GET /databases
      TableListHandler.php        # GET /databases/{db}/tables
      RowListHandler.php          # GET /tables/{db}/{table}/rows
      StructureHandler.php        # GET /tables/{db}/{table}/structure
  Exception/
    DbcException.php
    DriverException.php
    NotFoundException.php

config/
  connections.json             # connection definitions
