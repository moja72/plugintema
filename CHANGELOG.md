# Changelog

## [0.8.21] - 2025-10-29
### Fixed
- Ignoramos o comando `rclone userinfo` quando a versão instalada não o suporta, cacheando o resultado e evitando logs repetidos
  de falha ao coletar os dados do Drive.
- Registramos uma nota única quando o recurso está ausente e limpamos o cache associado ao forçar uma nova leitura.

## [0.8.20] - 2025-10-29
### Changed
- Refatoramos o disparo de backups manuais para usar a fila do cron minutely, garantindo que o clique no painel apenas agende o processo e que a execução aconteça fora da requisição do admin.
- Mantivemos o cron ativo sempre que houver job manual ou plano de chunks pendente, agendando novos ticks prioritários para respeitar o lock e evitar disparos simultâneos.

## [0.8.19] - 2025-10-29
### Fixed
- Reforçamos o script de backup para limpar variáveis `RCLONE_FILTER*` em um subshell dedicado e registrar o comando que falhou,
  evitando abortos silenciosos e fornecendo contexto para o watchdog dos chunks.
- Passamos a exigir o marcador `PTSB_RCLONE_FILTER_SUPPORT=1` ao detectar suporte do shell script às variáveis de filtro, impedindo
  falsos positivos quando ainda existe um script legado sem essa funcionalidade.
- Permitimos que um plano de chunks em andamento continue fora da janela de manutenção, mantendo apenas novas rotinas bloqueadas
  e reduzindo interrupções com o lock ativo.
- Validamos se o processo associado ao lock ainda está vivo e sincronizamos o arquivo `/tmp/wpbackup.lock`, liberando travas
  órfãs rapidamente.
- Executamos um pré-cheque do remoto `rclone` antes de disparar o backup e enriquecemos os logs de quota/e-mail com detalhes do
  erro retornado pelo CLI.

## [0.8.18] - 2025-10-29
### Fixed
- Exportamos as variáveis `RCLONE_FILTER*` apenas quando o script de backup suporta limpar os filtros para uploads individuais,
  restaurando a compatibilidade com instalações que ainda utilizam o shell script legado e impedindo falhas no `rclone copyto`.

## [0.8.17] - 2025-10-29
### Fixed
- Ignoramos variáveis `RCLONE_FILTER*` ao enviar o bundle final com `rclone copyto`, evitando falhas ao subir arquivos únicos e permitindo que os backups sejam concluídos com sucesso.

## [0.8.16] - 2025-10-29
### Fixed
- Tornamos o script `wp-run-wpcron.sh` resiliente, permitindo sobrescrever caminhos via variáveis de ambiente e validando binários
  antes da execução para evitar falhas silenciosas no cron do sistema.

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
