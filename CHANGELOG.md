# Changelog

## [0.10.0] - 2025-10-27
### Added
- Agendamento imediato das execuções manuais via cron dedicado, incluindo criação da fila de jobs (`ptsb_manual_job_*`).
- Novas opções de agenda avançada permitindo modos semanal, a cada N dias e X execuções distribuídas por dia.

### Changed
- Melhoria das mensagens exibidas no disparo manual para informar o status da fila e bloqueios ativos.
- Registro dos parâmetros de retenção e prefixo no payload do job manual para execução consistente pelo cron.

### Fixed
- Normalização dos horários informados pelo usuário evitando agendamentos inválidos e intervalos menores que o mínimo configurado.
