# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Common Commands

### Testing
- **Run all tests**: `composer test` or `phpunit`
- **Run specific test**: `composer tf TestName` or `./vendor/bin/phpunit --filter TestName`
- **Run PHPStan and tests together**: `composer ts`

### Static Analysis
- **PHPStan analysis**: `composer phpstan` or `phpstan analyse --memory-limit 2G`
- **PHPStan level**: 9 (highest level)

### Linting
- **PHP CS Fixer**: Available as a dev dependency but no script defined in composer.json
- **PHP Insights**: Available as a dev dependency (`nunomaduro/phpinsights`) but no script defined

## Architecture Overview

### Core ETL Pipeline
The package follows a classic ETL (Extract, Transform, Load) pattern using the Pipeline design pattern via `league/pipeline`:

1. **EtlPipe** (`src/EtlPipe.php`): Main entry point providing fluent API
   - Creates instances with `EtlPipe::make()`
   - Chain methods: `->extract()->transform()->load()->run()`

2. **Processor** (`src/Processor.php`): Orchestrates the ETL process
   - Uses League Pipeline for transformer chaining
   - Processes data from extractor through transformers to loader
   - Handles Frame objects throughout the pipeline

3. **Frame** (`src/Frame.php`): Core data container
   - Wraps data with Laravel Collections
   - Maintains header/data separation
   - Supports attributes for metadata
   - Uses `end` flag for stream termination

### Component Interfaces
All components implement contracts in `src/Contracts/`:
- **ExtractorInterface**: Data sources (CSV, XLSX, SQL)
- **TransformerInterface**: Data transformations
- **LoaderInterface**: Data destinations

### Laravel Integration
- Service provider: `PipesPackageServiceProvider`
- Facades available in `src/Facades/` for common components
- Supports Laravel 8-12
- Uses Laravel Collections and Database components

### Testing Structure
- Orchestra Testbench for Laravel package testing
- Test artifacts in `tests/artifacts/`
- Database migrations in `tests/migrations/`
- Unit tests organized by component type

## Development Tips

### Adding New Components
1. Implement the appropriate interface (`ExtractorInterface`, `TransformerInterface`, or `LoaderInterface`)
2. Follow existing patterns for static `make()` factory methods
3. Use DTOs for transformer configuration (see `src/DataTransferObjects/`)
4. Add corresponding unit tests following the existing structure

### Working with Transformers
- Transformers receive and return `Frame` objects
- Use Laravel Collections methods for data manipulation
- Maintain header/data consistency when transforming
- Check existing transformers for patterns and conventions