<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php'; require_once __DIR__ . '/../config/auth.php'; require_once __DIR__ . '/../services/AdminCatalogService.php';
requireMethod('POST'); $user=requireAuth(['administrador']); $data=requestJson();
try{
 $scope=(string)($data['alcance']??''); $preview=filter_var($data['solo_resumen']??false,FILTER_VALIDATE_BOOLEAN); $posts=[];
 if($scope==='estructura'){
  $id=adminId($data['poste_id']??null,'Estructura');$post=adminFetch($conn,'postes',$id);$posts=[$post];$stats=adminCleanupStats($conn,(int)$post['proyecto_id'],null,$id);$label=(string)$post['codigo'];
 }elseif($scope==='linea'){
  $pid=adminId($data['proyecto_id']??null,'Proyecto');$line=adminText($data['linea']??null,100,'Línea');$q=$conn->prepare('SELECT * FROM postes WHERE proyecto_id=? AND linea=? AND activo=1 ORDER BY id');$q->execute([$pid,$line]);$posts=$q->fetchAll();$stats=adminCleanupStats($conn,$pid,$line);$label=$line;
 }else throw new InvalidArgumentException('Alcance no válido.');
 if($preview)respond(['success'=>true,'data'=>array_merge($stats,['alcance'=>$scope,'etiqueta'=>$label])]);
 if((string)($data['confirmacion']??'')!==$label)throw new InvalidArgumentException('La confirmación no coincide.');
 if($posts===[])throw new DomainException('No hay estructuras activas para limpiar.');
 $conn->beginTransaction();$snapshots=[];$archived=0;$stamp=date('Ymd_His').'_'.bin2hex(random_bytes(3));
 foreach($posts as $post){$locked=adminFetch($conn,'postes',(int)$post['id'],true);$snapshot=adminInspectionSnapshot($conn,$locked);$archived+=adminArchiveInspectionFiles($snapshot,$stamp);$snapshots[]=$snapshot;adminClearPost($conn,$locked);}
 $context=['proyecto_id'=>(int)$posts[0]['proyecto_id'],'linea'=>$scope==='linea'?$label:$posts[0]['linea'],'poste_id'=>$scope==='estructura'?(int)$posts[0]['id']:null];
 $action=$scope==='estructura'?'structure_cleared':'line_cleared';$before=$scope==='estructura'?$snapshots[0]:$stats;$after=['estructuras'=>count($posts),'estado'=>'pendiente','archivos_conservados'=>$archived,'archivo'=>$stamp];adminAudit($conn,$user,$action,$scope,$context,$before,$after);$conn->commit();
 respond(['success'=>true,'message'=>$scope==='estructura'?'La estructura quedó lista para una nueva inspección.':'La línea quedó lista para nuevas inspecciones.','data'=>$after]);
}catch(InvalidArgumentException $e){if($conn->inTransaction())$conn->rollBack();respond(['success'=>false,'error'=>$e->getMessage()],422);}catch(DomainException $e){if($conn->inTransaction())$conn->rollBack();respond(['success'=>false,'error'=>$e->getMessage()],404);}catch(Throwable $e){if($conn->inTransaction())$conn->rollBack();appLog('administracion','Falló una limpieza administrativa.',['motivo'=>$e->getMessage()]);respond(['success'=>false,'error'=>'No se pudo completar la operación. No se realizaron cambios.'],500);}
