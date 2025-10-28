# Changelog

## [0.8.5] - 2025-10-28
### Changed
- Ajustadas variáveis de ambiente do rclone para limitar concorrência, habilitar delta incremental e aplicar filtros recentes de uploads.
- Tornado configurável o uso de `--fast-list` nas rotinas internas para reduzir consumo de memória quando necessário.

## [0.8.4] - 2025-10-28
### Added
- Implemented queue-based chunking for backups, criando jobs menores sequenciais para reduzir picos de recursos.

### Changed
- Ajustada a rotina de notificação para aguardar todos os chunks antes de finalizar o backup.
