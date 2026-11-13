<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php'; require_once __DIR__ . '/../config/auth.php'; require_once __DIR__ . '/../services/AdminCatalogService.php';
requireMethod('GET'); requireAuth(['administrador']);
try{
 [$page,$limit,$offset]=adminPagination();$entity=trim((string)($_GET['entidad']??''));$action=trim((string)($_GET['accion']??''));$project=filter_var($_GET['proyecto_id']??null,FILTER_VALIDATE_INT);$w=[];$p=[];
 if($entity!==''){$w[]='entidad_tipo=?';$p[]=$entity;}if($action!==''){$w[]='accion=?';$p[]=$action;}if($project){$w[]='proyecto_id=?';$p[]=$project;}$where=$w?' WHERE '.implode(' AND ',$w):'';
 $c=$conn->prepare("SELECT COUNT(*) FROM admin_auditoria{$where}");$c->execute($p);$q=$conn->prepare("SELECT id,usuario_id,usuario_nombre,accion,entidad_tipo,proyecto_id,linea,poste_id,resultado,detalle,creado_en FROM admin_auditoria{$where} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}");$q->execute($p);respond(['success'=>true,'data'=>$q->fetchAll(),'total'=>(int)$c->fetchColumn(),'pagina'=>$page,'limite'=>$limit]);
}catch(Throwable $e){appLog('administracion','Falló consulta de auditoría.',['motivo'=>$e->getMessage()]);respond(['success'=>false,'error'=>'No se pudo cargar el historial.'],500);}
