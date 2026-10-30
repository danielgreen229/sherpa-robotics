import { defineConfig } from "@playwright/test";
export default defineConfig({
  testDir: "./tests",
  fullyParallel: false,
  workers: 1,
  timeout: 180000,
  expect: { timeout: 15000 },
  use: {
    baseURL: process.env["BASE_URL"] ?? "http://localhost:8080",
    headless: true,
    trace: "retain-on-failure",
  },
  projects: [
    { name: "chromium", use: { browserName: "chromium" } },
    {
      name: "firefox",
      testMatch: /ui-glass\.spec\.ts/,
      use: { browserName: "firefox" },
    },
    {
      name: "webkit",
      testMatch: /ui-glass\.spec\.ts/,
      use: { browserName: "webkit" },
    },
  ],
  reporter: [["list"], ["html", { open: "never" }]],
});
