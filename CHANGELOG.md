# Changelog

## [0.8.9] - 2025-11-01
### Added
- Captura de métricas de execução no script de backup, incluindo duração por etapa, bytes transferidos, uso de CPU/I-O e pico de memória.
- Armazenamento de um histórico resumido das execuções com expiração automática para facilitar auditoria de performance.

## [0.8.8] - 2025-10-31
### Added
- Script dedicado para acionar o `wp cron event run --due-now` via WP-CLI, permitindo agendamento pelo cron do sistema.
- Orientação na aba de configurações sobre como configurar o cron externo utilizando o novo script.

## [0.8.7] - 2025-10-30
### Changed
- Implementado carregamento assíncrono dos detalhes dos backups com limite de 20 itens por requisição, evitando a decodificação imediata de manifests volumosos na interface.
- Ajustadas paginações das abas de backups, próximas e últimas execuções para trabalharem com lotes de até 20 itens, reduzindo a carga inicial da página.

## [0.8.6] - 2025-10-29
### Changed
- Empacotado script de backup assíncrono que utiliza `mysqldump --single-transaction --quick`, compressão via `pigz`/`gzip` e upload por etapas com `rclone`, evitando bloqueio da requisição do painel.
- Configuração passa a priorizar o script distribuído com o plugin, mantendo fallback para o caminho legado.

## [0.8.5] - 2025-10-28
### Changed
- Ajustadas variáveis de ambiente do rclone para limitar concorrência, habilitar delta incremental e aplicar filtros recentes de uploads.
- Tornado configurável o uso de `--fast-list` nas rotinas internas para reduzir consumo de memória quando necessário.

## [0.8.4] - 2025-10-28
### Added
- Implemented queue-based chunking for backups, criando jobs menores sequenciais para reduzir picos de recursos.

### Changed
- Ajustada a rotina de notificação para aguardar todos os chunks antes de finalizar o backup.
