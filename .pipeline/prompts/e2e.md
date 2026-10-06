YOUR NODE: E2E.

Write and run an end-to-end smoke test for the main flow in the role spec. Start the real app on a free port and drive it through its real HTTP API (or a headless browser if one is already installed; for the truck, use a phone-sized viewport if a browser is available). Put it under tests/e2e. Extend .pipeline/test-cmd.txt so it also runs tests/e2e; do not remove existing commands. If the test finds product bugs, fix the product (never edit tests/acceptance). Commit.
