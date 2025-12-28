# Contributing

Contributions are welcome! Whether you want to report a bug, suggest an improvement, or open a pull request, please follow the guidelines below to keep everything running smoothly.

## Code of Conduct

By participating in this project, you agree to abide by our community guidelines:

- Be respectful and inclusive.
- Assume good intent and communicate clearly.
- Provide constructive feedback and collaborate in good faith.

If you encounter behavior that violates these principles, please open an issue or contact the maintainers privately.

## Getting Started

1. Fork the repository and clone it locally.
2. Ensure you have a supported version of PHP installed.
3. Run `composer install` to install dependencies.
4. Ensure you have SQLite (or your chosen database) available if you plan to run the full test suite.

## Development Workflow

1. Create a feature branch from `main` (e.g. `feature/my-improvement`).
2. Make your changes in small, logical commits. Write descriptive commit messages.
3. Run the test suite locally before submitting a pull request:
   ```bash
   php artisan test -- --stop-on-failure
   ```
4. If you add new functionality, include or update tests and documentation where appropriate.
5. Update the `doc.md` file if your change affects the public API or behavior.

## Pull Requests

When submitting a pull request:

- Describe the problem your change solves and how to test it.
- Keep the scope focused; multiple small PRs are generally easier to review than a single large one.
- Reference related issues or discussions if applicable.
- Be responsive to feedback—PRs often involve iteration.

## Reporting Issues

If you encounter a bug or have a feature request:

1. Search [existing issues](https://github.com/Behind-Solutions/laravel-query-gate/issues) to avoid duplicates.
2. Provide detailed steps to reproduce the issue, including relevant logs, error messages, or screenshots.
3. Explain the expected vs. actual behavior.

## Release Process

Releases are managed by the maintainers. If your contribution is included, you will be credited in the release notes. Follow semantic versioning principles for any changes that affect the public API.

Thank you for helping improve Query Gate! Your contributions are appreciated.

