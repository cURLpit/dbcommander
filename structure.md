# DBCommander Backend Structure

src/
  Config/
    ConnectionConfig.php       # JSON config loading + validation
  Driver/
    DriverInterface.php        # common interface over PDO + mysqli
    PdoDriver.php
    MysqliDriver.php
    DriverFactory.php          # instantiation based on config
  Repository/
    DatabaseRepository.php     # SHOW DATABASES
    TableRepository.php        # SHOW TABLES, SHOW CREATE, etc.
    RowRepository.php          # SELECT rows + pagination
    StructureRepository.php    # INFORMATION_SCHEMA queries
  Http/
    Middleware/
      JsonResponseMiddleware.php     # PSR-15: Content-Type + JSON encode
      ErrorHandlerMiddleware.php     # PSR-15: exception → JSON error
      ConnectionMiddleware.php       # X-Connection → __driver, __driver_target
      CopyPrepareMiddleware.php      # table copy: validate + ensureTargetTable + LoopContext init
      TableCopySourceMiddleware.php  # table/db copy loop body: fetch one page from source
      TableCopyTargetMiddleware.php  # table/db copy loop body: insert one page into target
      CopyResponseMiddleware.php     # table copy: final JSON response + ANALYZE
      DbCopyPrepareMiddleware.php    # db copy: validate, create target DB, list tables, init LoopContext
      DbCopyIterateMiddleware.php    # db copy loop body: set __copy_source/__copy_target, ensure schema, advance table index
      DbCopyResponseMiddleware.php   # db copy: final JSON response + ANALYZE all copied tables
    Handler/
      DatabaseListHandler.php        # GET /databases
      TableListHandler.php           # GET /databases/{db}/tables
      RowListHandler.php             # GET /tables/{db}/{table}/rows
      StructureHandler.php           # GET /tables/{db}/{table}/structure
      CreateDatabaseHandler.php      # POST /databases
      CreateTableHandler.php         # POST /databases/{db}/tables
      UpdateRowHandler.php           # PUT  /tables/{db}/{table}/rows
      ModifyColumnHandler.php        # PUT  /tables/{db}/{table}/structure
      DropTableHandler.php           # DELETE /tables/{db}/{table}
      SqlHandler.php                 # POST /sql
  Exception/
    DbcException.php
    DriverException.php
    NotFoundException.php

config/
  connections.json             # connection definitions
