# Changelog

All notable changes to Weaver ORM are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.9.0] - 2026-03-28

### Added
- PyroSQL native driver via PostgreSQL wire protocol (PyroSQL is PG-compatible).

- `PyroSqlDriver` for feature detection (`isPyroSql()`, `getVersion()`, `supportsFullTextSearch()`).
- `PyroSqlSyntax` static helper: `find()`, `add()`, `change()`, `remove()`, `search()`, `nearest()`, `sample()`, `upsert()`, `importCsv()`, `protect()`.
- `PyroQueryBuilderExtension` trait: `timeTravel()`, `approximate()`, `nearest()`, `search()`, `sample()`, `asOf()`.

- Driver `pyrosql` available in Symfony configuration.

### Changed
- `Configuration.php` now accepts `pyrosql` as driver value.

## [0.8.0] - 2026-03-21

### Added
- Second Level Cache with PSR-16 adapter: `SecondLevelCache`, `CacheRegion`, `CacheConfiguration`.
- `#[Cache(region: 'users', ttl: 3600)]` attribute for entity-level cache configuration.
- `CacheUsage` enum: `READ_WRITE`, `READ_ONLY`, `NONSTRICT_READ_WRITE`.
- `QueryResultCache` with `->cache(60)` and `->invalidateCache()` on `EntityQueryBuilder`.
- `ArrayCache` in-memory PSR-16 implementation for testing.
- UnitOfWork integration: L2 cache auto-update on `push()`, auto-evict on `delete()`.
- Multi-database support: `ConnectionRegistry`, `ConnectionFactory`, `WorkspaceRegistry`.
- `#[Connection('analytics')]` attribute to bind entities to named connections.
- Lazy connection initialization — connections created on first access.
- Joined Table Inheritance: `JoinedInheritanceResolver` with automatic JOIN generation, multi-table INSERT/UPDATE/DELETE.
- `SchemaGenerator` now creates separate parent/child tables for `JOINED` strategy.
- Nested embeddables: recursive `#[Embedded]` resolution with combined column prefixes.
- Event listener priorities: `addListener($event, $callback, priority: 100)`.
- Runtime filter toggle: `QueryFilterRegistry::enable()`, `disable()`, `isEnabled()`.
- Criteria/Specification pattern: `Criteria`, `Expression`, `CriteriaApplier`, `->matching()` on query builder.
- Batch operations: `BatchProcessor::insertBatch()`, `updateBatch()`, `upsertBatch()`, `deleteBatch()`.
- `EntityWorkspace::addBatch()` and `pushBatch()` convenience methods.

### Changed
- `LifecycleEventDispatcher` now sorts listeners by priority (higher = earlier).
- `QueryFilterRegistry::register()` accepts a name parameter; filters can be toggled at runtime.
- `EmbeddedDefinition` supports `$nestedEmbeddables` array for recursive value objects.
- `InheritanceMapping` accepts `JOINED` strategy constant with parent/child table mapping.

### Fixed
- `EntityHydrator` now recursively hydrates nested embeddable objects.
- `AttributeMapperFactory` discovers `#[Embedded]` attributes inside embeddable classes.

## [0.7.0] - 2026-03-14

### Added
- Weaver-native API vocabulary replacing Doctrine naming conventions.
- `EntityWorkspace` (replaces `EntityManager`): `add()`, `push()`, `delete()`, `untrack()`, `isTracked()`, `reload()`, `reset()`.
- Entity inspection: `isDirty()`, `getChanges()`, `isNew()`, `isManaged()`, `isDeleted()`.
- Lifecycle attributes renamed: `#[BeforeAdd]`, `#[AfterAdd]`, `#[BeforeDelete]`, `#[AfterDelete]`, `#[BeforeUpdate]`, `#[AfterUpdate]`, `#[AfterLoad]`.
- Inheritance attributes renamed: `#[Inheritance]`, `#[TypeColumn]`, `#[TypeMap]`, `#[Superclass]`.
- Doctrine compatibility bridge: `DoctrineCompatEntityManager`, `DoctrineCompatUnitOfWork`.
- Bridge lifecycle attributes (`PrePersist`, `PostPersist`, etc.) extending new base classes.
- `DoctrineLifecycleEvents` constant class pointing old event names to new values.
- `ReflectionAttribute::IS_INSTANCEOF` flag in `EntityLifecycleInvoker` and `AttributeMapperFactory` — supports both old and new attributes transparently.

### Changed
- `UnitOfWorkInterface` methods: `persist→add`, `flush→push`, `remove→delete`, `clear→reset`, `detach→untrack`, `contains→isTracked`, `refresh→reload`.
- `LifecycleEvents` constants renamed: `PRE_PERSIST→BEFORE_ADD`, `POST_PERSIST→AFTER_ADD`, `PRE_FLUSH→BEFORE_PUSH`, `POST_FLUSH→AFTER_PUSH`, `ON_FLUSH→ON_PUSH`, `ON_CLEAR→ON_RESET`, etc.
- All repository and test code updated to new vocabulary.

### Removed
- `EntityManager` class (replaced by `EntityWorkspace`).
- Old lifecycle attribute classes (`PrePersist`, `PostPersist`, etc.) moved to Doctrine bridge.
- Old inheritance attributes (`MappedSuperclass`, `DiscriminatorColumn`, `DiscriminatorMap`, `InheritanceType`) moved to bridge.

## [0.6.0] - 2026-03-07

### Added
- PyroSQL module: driver detection, time travel, branching, CDC, approximate queries, vector search, WASM UDFs.
- `PyroSqlDriver` with lazy feature detection via `current_setting('pyrosql.version', true)`.
- `TimeTravelQueryBuilder`: `asOf()`, `asOfVersion()`, `current()` for `AS OF TIMESTAMP` / `AS OF LSN` queries.
- `PyroBranch` and `PyroBranchManager`: `create()`, `list()`, `switch()`, `delete()`, `mergeTo()`, `storageBytes()`.
- `CdcSubscriber`: `subscribe()`, `subscribeMany()`, `unsubscribe()`, `poll()` returning `CdcEvent` objects.
- `ApproximateQueryBuilder`: `count()`, `sum()`, `avg()`, `within()`, `confidence()`, `withFallback()`.
- `VectorSearch::nearestNeighbors()` with cosine, L2, and dot-product operators.
- `VectorIndex`: `create()`, `drop()`, `list()`, `exists()` for HNSW and IVFFlat indexes.
- `VectorColumnDefinition` for mapper column declarations.
- `WasmUdfManager`: `registerFromBase64()`, `registerFromFile()`, `list()`, `exists()`, `drop()`.
- `PyroQueryBuilderExtension` trait: `timeTravel()`, `approximate()`.
- SQL injection prevention: `quoteIdentifier()` and `quote()` on all user-controlled DDL inputs.
- `assertSqlType()` validation in `WasmUdfManager` for SQL type arguments.

### Fixed
- SQL injection vulnerabilities in `PyroBranch`, `PyroBranchManager`, `CdcSubscriber`, `VectorSearch`, and `WasmUdfManager` — all user-controlled values now properly escaped.

## [0.5.0] - 2026-02-28

### Added
- `SchemaDiffer` and `SchemaDiff` value object for comparing mapper schema vs database schema.
- Console commands: `weaver:schema:diff` and `weaver:schema:sql`.
- `SchemaValidator` for mapping validation against the live database.
- `MigrationSchemaProvider` for Doctrine Migrations integration.
- `EntityProxyGenerator` using PHP 8.4 property hooks for lazy-loading relations.
- `EntityProxyLoader` for automatic proxy class management.
- Implicit joins via dot-notation in `where()` / `orderBy()` (`user.profile.city`).
- Full-text search: `whereFullText()`, `whereFullTextBoolean()`, `orderByRelevance()`.
- MongoDB support: `AbstractDocumentMapper`, `DocumentQueryBuilder`, `DocumentPersistence`, `MongoConnectionFactory`.

### Changed
- `SchemaGenerator` now emits `SchemaDiff` objects instead of raw SQL arrays.
- Proxy generation no longer requires a separate library.

## [0.4.0] - 2026-02-14

### Added
- `N1Detector` with configurable thresholds and `QueryAssertions` trait for tests.
- `QueryProfiler` with per-query timing, Symfony DataCollector integration, and web debug toolbar panel.
- `ProfilingDriver`, `ProfilingConnection`, `ProfilingStatement`, `ProfilingMiddleware` — full DBAL middleware stack.
- `OnFlushEvent` and `PostFlushEvent` with changeset inspection.
- Built-in column types: `JsonType`, `UuidType`, `IpAddressType`, `PhoneType`, `EncryptedStringType`, `EnumStringType`, `MoneyType`, `UlidType`.
- `TypeRegistry::registerBuiltins()` for batch type registration.
- Macro system for `EntityQueryBuilder` (`macro()`, `hasMacro()`, `__call()`).
- Window functions: `selectWindow()`, `rowNumber()`, `rank()`, `denseRank()`, `lag()`, `lead()`.
- CTE support: `withCte()` for recursive and non-recursive common table expressions.

### Changed
- `EntityQueryBuilder` now supports method macros for project-specific extensions.
- Profiler middleware auto-registers when `debug: true` in Symfony config.

## [0.3.0] - 2026-02-07

### Added
- `EntityFactory` for test fixture generation with states and sequences.
- `WeaverTestCase`, `DatabaseTransactions`, and `RefreshDatabase` testing traits.
- `Faker` utility for generating test data.
- `ReadWriteConnection` for primary/replica database splitting.
- `TransactionManager` with savepoint support, deadlock retry with exponential backoff.
- Optimistic locking via `#[Version]` attribute and `OptimisticLockException`.
- `CachingRepository` with configurable TTL and cache adapter.
- `EntityCollection`: `filter()`, `map()`, `pluck()`, `groupBy()`, `sortBy()`, `contains()`, `isEmpty()`.
- `Paginator` with offset-based (`paginate()`), simple (`simplePaginate()`), and cursor-based (`cursorPaginate()`) strategies.
- `Page`, `SimplePage`, and `CursorPage` value objects.

### Changed
- `UnitOfWork` now supports selective flush with entity class filter.
- `AbstractRepository` accepts optional cache adapter injection.

## [0.2.0] - 2026-01-24

### Added
- `EntityQueryBuilder` with fluent API: `where()`, `orWhere()`, `whereIn()`, `whereNotIn()`, `whereNull()`, `whereNotNull()`, `whereBetween()`, `whereRaw()`.
- Ordering: `orderBy()`, `orderByDesc()`, `orderByRaw()`.
- Selection: `select()`, `addSelect()`, `selectRaw()`.
- Result methods: `get()`, `first()`, `firstOrFail()`, `count()`, `exists()`.
- Eager loading: `with()` for relation prefetching.
- Soft deletes: `#[SoftDeletes]` attribute, `HasSoftDeletes` concern, `SoftDeleteScope`, `SoftDeleteFilter`.
- `withTrashed()`, `onlyTrashed()`, `withoutTrashed()` query scopes.
- `ScopeInterface` and `TenantScope` for multi-tenancy.
- `QueryFilterInterface` and `QueryFilterRegistry` for global filters.
- Relation loaders: `BelongsToLoader`, `HasManyLoader`, `HasOneLoader`, `BelongsToManyLoader`, `HasManyThroughLoader`, `HasOneThroughLoader`, `MorphOneLoader`, `MorphManyLoader`.
- `EagerLoadPlan` for optimized relation fetching.
- `NativeQuery` with `ResultSetMapping` for raw SQL → entity hydration.

### Changed
- `EntityRepository` now returns `EntityQueryBuilder` from `query()`.
- Relation definitions support cascade options (`cascade: [CascadeType::PERSIST, CascadeType::REMOVE]`).

## [0.1.0] - 2026-01-10

### Added
- Initial release of Weaver ORM.
- `AbstractEntityMapper` and `AttributeEntityMapper` for entity ↔ table mapping.
- PHP attributes: `#[Entity]`, `#[Id]`, `#[Column]`, `#[Timestamps]`, `#[UseUuid]`, `#[UseUuidV7]`.
- Relation attributes: `#[HasOne]`, `#[HasMany]`, `#[BelongsTo]`, `#[BelongsToMany]`.
- `#[Embeddable]` and `#[Embedded]` for value objects.
- Single-table inheritance: `#[Inheritance]`, `#[TypeColumn]`, `#[TypeMap]`, `#[Superclass]`.
- `MapperRegistry` for mapper discovery and resolution.
- `UnitOfWork` with identity map, change tracking via `WeakMap` snapshots, and dirty checking.
- `EntityWorkspace` as the main entry point for entity persistence.
- `ManagerRegistry` for workspace lifecycle management.
- `EntityHydrator` and `PivotHydrator` for database result → entity conversion.
- `EntityRepository` with `find()`, `findOrFail()`, `findBy()`, `findAll()`.
- `RepositoryFactory` for automatic repository instantiation.
- `SchemaGenerator` for DDL generation from mapper definitions.
- Console commands: `weaver:schema:create`, `weaver:schema:update`, `weaver:schema:drop`, `weaver:debug:mappers`.
- `WeaverBundle` for Symfony with auto-configuration of mappers via `weaver.mapper` tag.
- `WeaverExtension` and `Configuration` for `config/packages/weaver.yaml`.
- `MapperRegistryPass` compiler pass for tagged mapper injection.
- Supports `pdo_mysql`, `pdo_pgsql`, `pdo_sqlite` drivers.
- `InsertOrderResolver` for topological sort of entity dependencies.
- `BiDirectionalLinker` for automatic inverse relation setup.
- `ChangeSet` and `EntitySnapshot` for tracking entity mutations.
- MIT License.

[0.9.0]: https://github.com/Unapartidamas/weaverORM/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/Unapartidamas/weaverORM/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/Unapartidamas/weaverORM/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/Unapartidamas/weaverORM/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/Unapartidamas/weaverORM/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/Unapartidamas/weaverORM/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/Unapartidamas/weaverORM/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/Unapartidamas/weaverORM/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/Unapartidamas/weaverORM/releases/tag/v0.1.0
