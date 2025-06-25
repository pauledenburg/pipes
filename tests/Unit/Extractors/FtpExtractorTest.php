<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use Tests\TestCase;
use Jwhulette\Pipes\Extractors\FtpExtractor;
use Jwhulette\Pipes\Extractors\CsvExtractor;

class FtpExtractorTest extends TestCase
{
    /**
     * Test basic FTP extraction functionality
     * 
     * Note: This is a basic test structure. In production, you would:
     * 1. Mock the FTP functions
     * 2. Use a test FTP server
     * 3. Create integration tests for real FTP scenarios
     */
    public function test_ftp_extractor_configuration()
    {
        $extractor = FtpExtractor::make('ftp.example.com', 'user', 'pass', '/path/to/file.csv');
        
        $this->assertInstanceOf(FtpExtractor::class, $extractor);
        
        // Test fluent interface
        $configured = $extractor
            ->setPort(2121)
            ->setPassive(false)
            ->setTimeout(120);
            
        $this->assertInstanceOf(FtpExtractor::class, $configured);
    }
    
    public function test_anonymous_ftp_creation()
    {
        $extractor = FtpExtractor::anonymous('ftp.example.com', '/pub/file.csv');
        
        $this->assertInstanceOf(FtpExtractor::class, $extractor);
    }
    
    public function test_custom_file_extractor_can_be_set()
    {
        $ftpExtractor = FtpExtractor::make('ftp.example.com', 'user', 'pass', '/path/to/file.csv');
        $csvExtractor = CsvExtractor::make('dummy.csv');
        
        $configured = $ftpExtractor->setFileExtractor($csvExtractor);
        
        $this->assertInstanceOf(FtpExtractor::class, $configured);
    }
}