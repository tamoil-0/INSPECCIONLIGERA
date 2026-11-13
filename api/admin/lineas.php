<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php'; require_once __DIR__ . '/../config/auth.php'; require_once __DIR__ . '/../services/AdminCatalogService.php';
$user=requireAuth(['administrador']); $method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
try {
 if($method==='GET'){
  [$page,$limit,$offset]=adminPagination(); $project=filter_var($_GET['proyecto_id']??null,FILTER_VALIDATE_INT); $state=trim((string)($_GET['estado']??'')); $search=trim((string)($_GET['busqueda']??'')); $w=[];$p=[];
  if($project){$w[]='l.proyecto_id=?';$p[]=$project;} if($state!==''){if(!in_array($state,['activa','inactiva'],true))throw new InvalidArgumentException('Estado no válido.');$w[]='l.activa=?';$p[]=$state==='activa'?1:0;} if($search!==''){$w[]='(l.nombre LIKE ? OR l.ubicacion LIKE ?)';$like="%{$search}%";array_push($p,$like,$like);} $where=$w?' WHERE '.implode(' AND ',$w):'';
  $c=$conn->prepare("SELECT COUNT(*) FROM lineas l{$where}");$c->execute($p); $q=$conn->prepare("SELECT l.*,pr.nombre_proyecto,
   (SELECT COUNT(*) FROM postes e WHERE e.proyecto_id=l.proyecto_id AND e.linea=l.nombre) estructuras_total,
   (SELECT COUNT(*) FROM postes e WHERE e.proyecto_id=l.proyecto_id AND e.linea=l.nombre AND e.activo=1) estructuras_activas,
   (SELECT COUNT(*) FROM postes e WHERE e.proyecto_id=l.proyecto_id AND e.linea=l.nombre AND e.formulario_subido=1) formularios,
   (SELECT COUNT(*) FROM imagenes_poste i JOIN postes e ON e.id=i.poste_id WHERE e.proyecto_id=l.proyecto_id AND e.linea=l.nombre AND i.activo=1) fotos,
   (SELECT COUNT(*) FROM poste_secciones_rst r JOIN postes e ON e.id=r.poste_id WHERE e.proyecto_id=l.proyecto_id AND e.linea=l.nombre AND r.activo=1) rst
   FROM lineas l JOIN proyectos pr ON pr.id=l.proyecto_id{$where} ORDER BY pr.nombre_proyecto,l.nombre LIMIT {$limit} OFFSET {$offset}");$q->execute($p);respond(['success'=>true,'data'=>$q->fetchAll(),'total'=>(int)$c->fetchColumn(),'pagina'=>$page,'limite'=>$limit]);
 }
 $d=requestJson();
 if($method==='POST'){
  $pid=adminId($d['proyecto_id']??null,'Proyecto');$name=adminText($d['nombre']??null,100,'Línea');$location=adminText($d['ubicacion']??null,255,'Ubicación',false);$conn->beginTransaction();$project=adminFetch($conn,'proyectos',$pid,true);if($project['estado']!=='activo')throw new DomainException('El proyecto debe estar activo.');
  $conn->prepare('INSERT INTO lineas(proyecto_id,nombre,ubicacion) VALUES(?,?,?)')->execute([$pid,$name,$location]);$id=(int)$conn->lastInsertId();$after=adminFetch($conn,'lineas',$id);adminAudit($conn,$user,'line_created','linea',['proyecto_id'=>$pid,'linea'=>$name],null,$after);$conn->commit();respond(['success'=>true,'message'=>'Línea creada.','data'=>$after],201);
 }
 if($method==='PATCH'){
  $id=adminId($d['id']??null,'Línea');$conn->beginTransaction();$before=adminFetch($conn,'lineas',$id,true);$action=(string)($d['accion']??'actualizar');
  if($action==='actualizar'){$name=adminText($d['nombre']??$before['nombre'],100,'Línea');$location=adminText($d['ubicacion']??$before['ubicacion'],255,'Ubicación',false);$dup=$conn->prepare('SELECT 1 FROM lineas WHERE proyecto_id=? AND nombre=? AND id<>?');$dup->execute([$before['proyecto_id'],$name,$id]);if($dup->fetchColumn())throw new InvalidArgumentException('Ya existe esa línea en el proyecto.');$conn->prepare('UPDATE postes SET linea=? WHERE proyecto_id=? AND linea=?')->execute([$name,$before['proyecto_id'],$before['nombre']]);$conn->prepare('UPDATE lineas SET nombre=?,ubicacion=? WHERE id=?')->execute([$name,$location,$id]);$event='line_updated';}
  elseif(in_array($action,['desactivar','reactivar'],true)){$active=$action==='reactivar'?1:0;$conn->prepare('UPDATE lineas SET activa=?,desactivada_en=IF(?=1,NULL,NOW()) WHERE id=?')->execute([$active,$active,$id]);$event=$active?'line_enabled':'line_disabled';}
  else throw new InvalidArgumentException('Acción no válida.');$after=adminFetch($conn,'lineas',$id);adminAudit($conn,$user,$event,'linea',['proyecto_id'=>$before['proyecto_id'],'linea'=>$after['nombre']],$before,$after);$conn->commit();respond(['success'=>true,'message'=>'Línea actualizada.','data'=>$after]);
 }
 respond(['success'=>false,'error'=>'Método no permitido.'],405);
}catch(InvalidArgumentException $e){if($conn->inTransaction())$conn->rollBack();respond(['success'=>false,'error'=>$e->getMessage()],422);}catch(DomainException $e){if($conn->inTransaction())$conn->rollBack();respond(['success'=>false,'error'=>$e->getMessage()],409);}catch(PDOException $e){if($conn->inTransaction())$conn->rollBack();$code=(int)($e->errorInfo[1]??0);respond(['success'=>false,'error'=>$code===1062?'Ya existe esa línea en el proyecto.':'No se pudo completar la operación. No se realizaron cambios.'],$code===1062?409:500);}catch(Throwable $e){if($conn->inTransaction())$conn->rollBack();appLog('administracion','Fallo en líneas admin.',['motivo'=>$e->getMessage()]);respond(['success'=>false,'error'=>'No se pudo completar la operación. No se realizaron cambios.'],500);}
