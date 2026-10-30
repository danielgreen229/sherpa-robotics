.PHONY: up down logs test analyse style format fixtures e2e check
up:
	sh setup.sh
down:
	docker compose down
logs:
	docker compose logs -f app worker nginx
test:
	docker compose exec app vendor/bin/phpunit
analyse:
	docker compose exec app vendor/bin/phpstan analyse --memory-limit=512M
style:
	docker compose exec app vendor/bin/php-cs-fixer fix --dry-run --diff
format:
	docker compose exec app vendor/bin/php-cs-fixer fix
fixtures:
	docker compose exec app php bin/console app:fixtures
e2e:
	cd e2e && npm ci && npx playwright install chromium firefox webkit && npm test
check: test analyse style
