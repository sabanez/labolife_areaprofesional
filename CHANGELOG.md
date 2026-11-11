# Changelog

## 2026-11-11
- Se anade `.gitignore` en la raiz del repo: ignora `wp-config.php`, `.env`, volcados
  `*.sql`/`*.sql.gz`, `*.log`, `*.zip`, `/backup/`, `/wp-content/ai1wm-backups/`,
  `.DS_Store` y `.bash_history`.
  Motivo: el repo se separo de un repo comun con otros proyectos y quedaban sin versionar
  ficheros sensibles que un `git add .` habria subido a GitHub.
  Limite: solo afecta a ficheros aun no versionados; si algo sensible ya se hubiera
  commiteado habria que sacarlo del indice (`git rm --cached`).
  Prueba: `git status` ya no muestra esos ficheros y `git check-ignore -v wp-config.php`
  indica la regla que lo ignora.
