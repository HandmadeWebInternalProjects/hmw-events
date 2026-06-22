#!/bin/bash

# LSA Adbuilder Plugin - Test Setup and Runner
# This script installs test dependencies and runs the test suite

echo "🧪 Setting up LSA Adbuilder Plugin Tests"
echo "======================================="

# Check if we're in the plugin directory
if [ ! -f "lsa-adbuilder.php" ]; then
    echo "❌ Error: Please run this from the plugin root directory"
    exit 1
fi

echo "📦 Installing test dependencies..."
composer install --dev

if [ $? -ne 0 ]; then
    echo "❌ Error: Failed to install Composer dependencies"
    exit 1
fi

echo "✅ Dependencies installed successfully!"
echo ""

# Check if PHPUnit is available
if [ ! -f "vendor/bin/phpunit" ]; then
    echo "❌ Error: PHPUnit not found. Dependencies may not have installed correctly."
    exit 1
fi

echo "🏃 Running test suite..."
echo "========================"

# Run tests with verbose output
./vendor/bin/phpunit --colors=always --verbose

TEST_EXIT_CODE=$?

echo ""
echo "📊 Test Results Summary:"
echo "========================"

if [ $TEST_EXIT_CODE -eq 0 ]; then
    echo "✅ All tests passed!"
    echo ""
    echo "🚀 Next steps:"
    echo "   • Add more test cases for edge cases"
    echo "   • Create integration tests for full workflows"
    echo "   • Set up continuous integration"
    echo "   • Add code coverage reporting"
else
    echo "❌ Some tests failed (exit code: $TEST_EXIT_CODE)"
    echo ""
    echo "🔧 Debugging tips:"
    echo "   • Check the test output above for specific failures"
    echo "   • Ensure all dependencies are installed"
    echo "   • Verify PHP version compatibility"
    echo "   • Check for missing WordPress functions"
fi

exit $TEST_EXIT_CODE