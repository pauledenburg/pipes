#!/bin/bash

# Setup script for git hooks

echo "Setting up git hooks for Pipes ETL Library..."

# Configure git to use our hooks directory
git config core.hooksPath .githooks

echo "✓ Git hooks configured successfully!"
echo ""
echo "The following hooks are now active:"
echo "- pre-commit: Runs quality checks before each commit"
echo ""
echo "To disable hooks temporarily, use: git commit --no-verify"
echo "To disable hooks permanently, run: git config --unset core.hooksPath"