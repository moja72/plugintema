# Changelog

## [0.1.1] - 2025-10-27
### Fixed
- Respeita as variáveis do `.env` ao sincronizar com o ambiente do processo antes de invocar o `rclone`, garantindo que remotos e credenciais sejam detectados corretamente.

## [0.1.0] - 2025-10-27
### Added
- Permite configurar o caminho do arquivo `.env` sensível via filtro dedicado.
- Lê pares de variáveis do `.env` com parsing seguro, reutilizando-os nos comandos shell.

### Changed
- Prefixo de ambiente shell reaproveita as variáveis secretas antes de invocar o `rclone`, com fallback silencioso quando o `.env` está ausente.
