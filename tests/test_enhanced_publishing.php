<?php
/**
 * Enhanced Multi-Platform Publishing System Tests
 * 
 * Tests for the enhanced features added to the unified publishing system:
 * - English/Arabic language support
 * - Instagram text posts
 * - Enhanced unified interface
 */

require_once dirname(__FILE__) . '/../social_content_model.php';

class EnhancedPublishingTest
{
    private $model;
    
    public function __construct()
    {
        // Mock CI framework dependencies
        if (!defined('BASEPATH')) {
            define('BASEPATH', true);
        }
        
        // Mock the model's dependencies
        $this->model = new Social_content_model();
    }
    
    /**
     * Test Instagram text post support
     */
    public function test_instagram_text_post_support()
    {
        echo "Testing Instagram text post support...\n";
        
        // Check if text posts are now supported for Instagram
        $supported_types = Social_content_model::POST_TYPES['instagram'];
        
        if (in_array('text', $supported_types)) {
            echo "✅ Instagram text posts are now supported in POST_TYPES\n";
        } else {
            echo "❌ Instagram text posts are NOT supported in POST_TYPES\n";
            return false;
        }
        
        return true;
    }
    
    /**
     * Test Facebook text post support (should still work)
     */
    public function test_facebook_text_post_support()
    {
        echo "Testing Facebook text post support...\n";
        
        $supported_types = Social_content_model::POST_TYPES['facebook'];
        
        if (in_array('text', $supported_types)) {
            echo "✅ Facebook text posts are supported\n";
        } else {
            echo "❌ Facebook text posts are NOT supported\n";
            return false;
        }
        
        return true;
    }
    
    /**
     * Test scheduling options
     */
    public function test_scheduling_options()
    {
        echo "Testing scheduling options...\n";
        
        $expected_types = ['daily', 'weekly', 'monthly', 'quarterly'];
        $actual_types = Social_content_model::RECURRENCE_TYPES;
        
        $missing = array_diff($expected_types, $actual_types);
        if (empty($missing)) {
            echo "✅ All required scheduling options are available: " . implode(', ', $actual_types) . "\n";
        } else {
            echo "❌ Missing scheduling options: " . implode(', ', $missing) . "\n";
            return false;
        }
        
        return true;
    }
    
    /**
     * Test content type validation
     */
    public function test_content_type_validation()
    {
        echo "Testing content type validation...\n";
        
        // Test that both platforms support the expected content types
        $facebook_types = Social_content_model::POST_TYPES['facebook'];
        $instagram_types = Social_content_model::POST_TYPES['instagram'];
        
        $expected_facebook = ['text', 'image', 'video', 'carousel', 'reel', 'story_photo', 'story_video'];
        $expected_instagram = ['text', 'image', 'video', 'carousel', 'reel', 'story_photo', 'story_video'];
        
        $facebook_missing = array_diff($expected_facebook, $facebook_types);
        $instagram_missing = array_diff($expected_instagram, $instagram_types);
        
        if (empty($facebook_missing) && empty($instagram_missing)) {
            echo "✅ All expected content types are supported for both platforms\n";
            echo "   Facebook: " . implode(', ', $facebook_types) . "\n";
            echo "   Instagram: " . implode(', ', $instagram_types) . "\n";
        } else {
            if (!empty($facebook_missing)) {
                echo "❌ Facebook missing: " . implode(', ', $facebook_missing) . "\n";
            }
            if (!empty($instagram_missing)) {
                echo "❌ Instagram missing: " . implode(', ', $instagram_missing) . "\n";
            }
            return false;
        }
        
        return true;
    }
    
    /**
     * Test enhanced UI functionality (check if language support is in place)
     */
    public function test_ui_enhancements()
    {
        echo "Testing UI enhancements...\n";
        
        // Check if the main upload file has language support
        $upload_file = dirname(__FILE__) . '/../social_publisher_upload.php';
        if (!file_exists($upload_file)) {
            echo "❌ Upload file not found\n";
            return false;
        }
        
        $content = file_get_contents($upload_file);
        
        // Check for language toggle functionality
        if (strpos($content, 'languageToggle') !== false) {
            echo "✅ Language toggle functionality found\n";
        } else {
            echo "❌ Language toggle functionality NOT found\n";
            return false;
        }
        
        // Check for translation support
        if (strpos($content, 'translations') !== false) {
            echo "✅ Translation system found\n";
        } else {
            echo "❌ Translation system NOT found\n";
            return false;
        }
        
        // Check for English translations
        if (strpos($content, 'Unified Publishing') !== false) {
            echo "✅ English translations found\n";
        } else {
            echo "❌ English translations NOT found\n";
            return false;
        }
        
        return true;
    }
    
    /**
     * Run all tests
     */
    public function run_all_tests()
    {
        echo "=== Enhanced Multi-Platform Publishing System Tests ===\n\n";
        
        $tests = [
            'test_instagram_text_post_support',
            'test_facebook_text_post_support', 
            'test_scheduling_options',
            'test_content_type_validation',
            'test_ui_enhancements'
        ];
        
        $passed = 0;
        $total = count($tests);
        
        foreach ($tests as $test) {
            if ($this->{$test}()) {
                $passed++;
            }
            echo "\n";
        }
        
        echo "=== Test Results ===\n";
        echo "Passed: {$passed}/{$total}\n";
        
        if ($passed === $total) {
            echo "🎉 All tests passed! Enhanced features are working correctly.\n";
            return true;
        } else {
            echo "⚠️  Some tests failed. Please review the implementation.\n";
            return false;
        }
    }
}

// Run tests if called directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'] ?? '')) {
    $test = new EnhancedPublishingTest();
    $test->run_all_tests();
}