# XML to JSON Pipeline for Large Files - Implementation Plan

## Project Overview

This document outlines the implementation plan for processing large XML files (150MB+) through the Pipes ETL framework. The goal is to merge data from multiple XML sources (Products.xml, Stock.xml, Groups.xml) into unified JSON output using SQLite as a temporary merge database.

## Requirements

- [ ] Support XML files up to 150MB+ without memory issues
- [ ] Merge data from multiple XML sources based on `EcommerceProductGuid`
- [ ] Use SQLite as temporary storage for efficient merging
- [ ] Automatic cleanup of temporary files/databases
- [ ] Memory-efficient streaming processing
- [ ] Progress tracking for long-running operations

## Architecture Decisions

1. **XMLReader** for streaming XML parsing (not SimpleXML)
2. **SQLite** for temporary data storage and merging
3. **NDJSON** output format for large result sets
4. **Chunked processing** to maintain constant memory usage

## Implementation Tasks

### 1. Core XML Processing Components

#### StreamingXmlExtractor
- [x] Create `src/Extractors/StreamingXmlExtractor.php`
- [x] Implement ExtractorInterface
- [x] Add XMLReader-based streaming parser
- [x] Support configurable XML element paths
- [ ] Add memory usage monitoring
- [ ] Handle XML namespaces
- [x] Add error handling for malformed XML
- [x] Create unit tests
- [ ] Add documentation

#### XmlExtractor (SimpleXML-based for small files)
- [x] Create `src/Extractors/XmlExtractor.php`
- [x] Implement ExtractorInterface
- [x] Add file size detection to auto-switch to streaming
- [x] Support XPath queries
- [x] Create unit tests
- [ ] Add documentation

### 2. SQLite Merge Components

#### SqliteMergeTransformer
- [x] Create `src/Transformers/SqliteMergeTransformer.php`
- [x] Implement TransformerInterface
- [x] Create temporary SQLite database in constructor
- [x] Add table creation logic for each source type
- [x] Implement data insertion with prepared statements
- [x] Add index creation for merge keys
- [x] Implement JOIN query for merging
- [x] Add cleanup in destructor
- [x] Support configurable merge strategies
- [x] Add transaction support for reliability
- [x] Implement progress callbacks
- [x] Create unit tests
- [ ] Add integration tests with real XML data
- [ ] Add documentation

#### Database Schema
- [ ] Define products table structure
- [ ] Define stock table structure  
- [ ] Define groups table structure
- [ ] Create indexes for performance
- [ ] Document schema decisions

### 3. Support Components

#### XmlToArrayTransformer
- [x] Create `src/Transformers/XmlToArrayTransformer.php`
- [x] Convert XML string/SimpleXMLElement to array
- [x] Handle attributes and namespaces
- [x] Support nested elements
- [x] Create unit tests
- [ ] Add documentation

#### CleanupLoader
- [x] Create `src/Loaders/CleanupLoader.php`
- [x] Implement LoaderInterface
- [x] Wrap existing loaders
- [x] Execute cleanup callbacks on completion
- [x] Handle cleanup errors gracefully
- [x] Create unit tests
- [ ] Add documentation

### 4. FTP Integration Updates

#### Update FtpExtractor
- [x] Add XML file type detection in `detectFileType()`
- [x] Integrate XmlExtractor for .xml files
- [x] Add file size check for streaming decision
- [ ] Update tests for XML support
- [ ] Update documentation

### 5. Configuration and Optimization

#### Memory Management
- [ ] Add memory limit configuration
- [ ] Implement memory usage tracking
- [ ] Add automatic garbage collection triggers
- [ ] Document memory optimization techniques

#### Performance Optimizations
- [ ] Add SQLite PRAGMA optimizations
- [ ] Implement batch insert logic
- [ ] Add configurable chunk sizes
- [ ] Create performance benchmarks

### 6. Testing

#### Unit Tests
- [ ] Test StreamingXmlExtractor with various XML structures
- [ ] Test SqliteMergeTransformer merge logic
- [ ] Test cleanup mechanisms
- [ ] Test error handling scenarios
- [ ] Test memory usage stays constant

#### Integration Tests
- [ ] Create test XML files (small, medium, large)
- [ ] Test complete pipeline FTP → XML → Merge → JSON
- [ ] Test with missing/incomplete data
- [ ] Test cleanup after errors
- [ ] Performance tests with 150MB+ files

#### Example Test Data
- [ ] Create sample Products.xml (with 1000+ products)
- [ ] Create sample Stock.xml (matching products)
- [ ] Create sample Groups.xml (category hierarchy)
- [ ] Create expected output JSON

### 7. Documentation

#### Code Examples
- [ ] Basic usage example
- [ ] Advanced configuration example
- [ ] Custom merge strategy example
- [ ] Error handling example
- [ ] Progress tracking example

#### API Documentation
- [ ] Document all public methods
- [ ] Add PHPDoc blocks
- [ ] Document configuration options
- [ ] Add troubleshooting guide

#### README Updates
- [ ] Add XML processing section
- [ ] Add large file handling section
- [ ] Add SQLite merge example
- [ ] Update feature list

### 8. Examples and Demos

#### Create Working Examples
- [ ] `examples/xml-simple-merge.php` - Basic example
- [ ] `examples/xml-large-files.php` - Large file example
- [ ] `examples/xml-ftp-to-json.php` - Complete FTP example
- [ ] `examples/xml-custom-merge.php` - Custom merge logic
- [ ] `examples/xml-progress-tracking.php` - Progress tracking

### 9. Error Handling and Recovery

#### Implement Robust Error Handling
- [ ] Add resume capability for interrupted processing
- [ ] Implement checkpoint system
- [ ] Add detailed error logging
- [ ] Create error recovery strategies
- [ ] Document error scenarios

### 10. Final Integration

#### Complete Pipeline Testing
- [ ] Test with real FTP server
- [ ] Test with production-size XML files
- [ ] Verify memory usage remains stable
- [ ] Confirm SQLite cleanup works
- [ ] Performance benchmarks

#### Code Review and Cleanup
- [ ] Run PHPStan analysis
- [ ] Fix any code style issues
- [ ] Remove debug code
- [ ] Optimize imports

## Implementation Order

1. **Phase 1**: Core Components (Tasks 1-3)
   - Start with StreamingXmlExtractor
   - Then SqliteMergeTransformer
   - Support components

2. **Phase 2**: Integration (Tasks 4-5)
   - Update FtpExtractor
   - Add optimizations

3. **Phase 3**: Testing & Documentation (Tasks 6-7)
   - Comprehensive testing
   - Documentation

4. **Phase 4**: Examples & Polish (Tasks 8-10)
   - Working examples
   - Error handling
   - Final integration

## Success Criteria

- [ ] Can process 150MB+ XML files with constant memory usage
- [ ] SQLite database is automatically cleaned up
- [ ] Merge produces correct output
- [ ] All tests pass
- [ ] Documentation is complete
- [ ] Examples work out of the box

## Notes

- Keep memory usage under 100MB regardless of file size
- SQLite file should be created in system temp directory
- Use transactions for reliability
- Consider adding progress events for UI integration

## Resources

- [PHP XMLReader Documentation](https://www.php.net/manual/en/book.xmlreader.php)
- [SQLite Performance Tips](https://www.sqlite.org/fasterthanfs.html)
- [PHP Generators for Memory Efficiency](https://www.php.net/manual/en/language.generators.overview.php)