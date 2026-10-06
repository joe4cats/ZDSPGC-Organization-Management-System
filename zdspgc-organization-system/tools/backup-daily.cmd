@echo off
rem Daily database backup — scheduled by Windows Task Scheduler ("ZDSPGC DB Backup").
"C:\xampp\php\php.exe" "C:\Users\Acer\Downloads\agent-spec-main\zdspgc-organization-system\tools\backup.php" --keep=14 >> "C:\Users\Acer\Downloads\agent-spec-main\zdspgc-organization-system\database\backups\task.log" 2>&1
