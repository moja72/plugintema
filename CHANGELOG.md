# Changelog

## [0.8.15] - 2025-10-28
### Changed
- Adicionamos cache transitório para quota e e-mail do Drive com expiração configurável e limpeza manual via "Forçar atualizar".
- Passamos a invalidar o cache quando o CLI falha, registrando log e exibindo placeholders na interface até a nova leitura.
- Tornamos configurável o nível de compressão dos arquivos do backup, passando a utilizar `-6` por padrão para equilibrar desempenho e tamanho final.
- Ajustamos os logs do script para indicar o nível aplicado durante a compressão com `pigz` ou `gzip`.
- Carregamos o mapa de arquivos com marca `.keep` sob demanda na rotina de notificação, evitando chamadas remotas desnecessárias e mantendo a criação do sidecar para backups "Sempre manter".

## [0.8.14] - 2025-10-28
### Removed
- Eliminamos estilos legados e variáveis JavaScript não utilizadas para manter os assets do painel mais leves.
- Eliminamos fallbacks legados que reconstruíam `PARTS` a partir das letras selecionadas, confiando na função atual `ptsb_letters_to_parts_csv`.
- Removemos o helper `ptsb_map_ui_codes_to_parts`, que não era mais utilizado após a migração da interface.

## [0.8.13] - 2025-11-03
### Changed
- Removemos comentários e blocos redundantes da UI, mantendo a página de backup mais enxuta sem perder funcionalidades.
- Centralizamos os metadados dos chips de seleção de partes, evitando duplicação de marcação em `inc/ui.php`.

## [0.8.12] - 2025-10-28
### Removed
- Eliminamos helpers e utilitários não utilizados para simplificar a base de código.

## [0.8.11] - 2025-11-02
### Changed
- Restringimos as execuções automáticas às janelas de manutenção configuradas (padrão 02:00–05:00 BRT), mantendo a fila pausada até o horário liberado.
- Ajustamos o disparo do script para aplicar prioridades configuráveis via nice/ionice e, quando disponível, limitar CPU com `cpulimit`.

## [0.8.10] - 2025-10-28
### Fixed
- Mantivemos as flags DONOTCACHEPAGE/DONOTCDN/DONOTCACHEDB diretamente na view e nas rotas AJAX para evitar cache do admin.
- Validamos todas as rotas AJAX com `check_ajax_referer` usando o nonce compartilhado da interface.

### Changed
- Limitamos o carregamento de CSS e JS da interface de backup apenas à tela `Ferramentas > Backup`, evitando assets globais no admin.

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
