# Changelog

## [0.8.6] - 2025-10-29
### Changed
- Implemented asynchronous backup pipeline using dedicated shell script with optimized mysqldump flags and background compression/upload stages.
- Exposed database credentials and metadata to the runner for manifest generation and chunk-aware logging.

## [0.8.5] - 2025-10-28
### Added
- Exportadas variáveis de ambiente padrão com parâmetros de delta (--max-age, --update e template ano/mês) para que os scripts shell usem cópias incrementais e filtros consistentes.

### Changed
- Ajustados os comandos globais do rclone para limitar transfers/checkers, reforçar políticas de retry/backoff e repassar essas opções via `RCLONE_FLAGS`.
- Tornado o uso de `--fast-list` opcional, controlado por configuração, e aplicado o mesmo comportamento às ações de renomear/apagar arquivos no Drive.

## [0.8.4] - 2025-10-28
### Added
- Implemented queue-based chunking for backups, criando jobs menores sequenciais para reduzir picos de recursos.

### Changed
- Ajustada a rotina de notificação para aguardar todos os chunks antes de finalizar o backup.
