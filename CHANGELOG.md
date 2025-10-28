# Changelog

## [0.8.4] - 2025-10-28
### Added
- Implemented queue-based chunking for backups, criando jobs menores sequenciais para reduzir picos de recursos.

### Changed
- Ajustada a rotina de notificação para aguardar todos os chunks antes de finalizar o backup.
