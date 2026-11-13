<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../services/AdminCatalogService.php';
require_once __DIR__ . '/../services/PlantillaService.php';
$user = requireAuth(['administrador']);

/**
 * Plantilla elegida para el proyecto. `null` (o vacío) = flujo fijo de siempre.
 * Solo se acepta una plantilla activa.
 */
function proyectoPlantillaElegida(PDO $db, mixed $valor): ?int
{
    if ($valor === null || $valor === '' || $valor === 0 || $valor === '0') return null;
    $id = adminId($valor, 'Plantilla');
    $q = $db->prepare('SELECT activa FROM plantillas WHERE id = ?'); $q->execute([$id]);
    $activa = $q->fetchColumn();
    if ($activa === false) throw new InvalidArgumentException('La plantilla elegida no existe.');
    if ((int) $activa !== 1) throw new InvalidArgumentException('La plantilla elegida está desactivada.');
    return $id;
}

/** Cambiar de plantilla con inspecciones hechas dejaría datos que el PDF ya no sabe dibujar. */
function proyectoTieneInspecciones(PDO $db, int $id): bool
{
    $q = $db->prepare('SELECT EXISTS(SELECT 1 FROM postes WHERE proyecto_id=? AND (formulario_subido=1 OR imagenes_subidas=1))
        OR EXISTS(SELECT 1 FROM poste_respuestas r JOIN postes p ON p.id=r.poste_id WHERE p.proyecto_id=?)
        OR EXISTS(SELECT 1 FROM imagenes_plantilla i JOIN postes p ON p.id=i.poste_id WHERE p.proyecto_id=? AND i.activo=1)');
    $q->execute([$id, $id, $id]);
    return (bool) $q->fetchColumn();
}
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
try {
    plantillasAsegurarEsquema($conn);
    if ($method === 'GET') {
        [$page,$limit,$offset] = adminPagination();
        $search=trim((string)($_GET['busqueda']??'')); $state=trim((string)($_GET['estado']??''));
        $where=[]; $params=[];
        if ($search!=='') { $where[]='(p.nombre_proyecto LIKE ? OR p.contratista LIKE ? OR p.ubicacion LIKE ?)'; $like="%{$search}%"; array_push($params,$like,$like,$like); }
        if ($state!=='') { if(!in_array($state,['activo','completado','cancelado'],true)) throw new InvalidArgumentException('Estado no válido.'); $where[]='p.estado=?'; $params[]=$state; }
        $sqlWhere=$where?' WHERE '.implode(' AND ',$where):'';
        $count=$conn->prepare("SELECT COUNT(*) FROM proyectos p{$sqlWhere}"); $count->execute($params);
        $q=$conn->prepare("SELECT p.id,p.nombre_proyecto,p.contratista,p.ubicacion,p.fecha_creacion,p.estado,p.plantilla_id,
          (SELECT t.nombre FROM plantillas t WHERE t.id=p.plantilla_id) plantilla_nombre,
          (SELECT COUNT(*) FROM lineas l WHERE l.proyecto_id=p.id) lineas_total,
          (SELECT COUNT(*) FROM lineas l WHERE l.proyecto_id=p.id AND l.activa=1) lineas_activas,
          (SELECT COUNT(*) FROM postes e WHERE e.proyecto_id=p.id) estructuras_total,
          (SELECT COUNT(*) FROM postes e WHERE e.proyecto_id=p.id AND e.activo=1) estructuras_activas
          FROM proyectos p{$sqlWhere} ORDER BY p.fecha_creacion DESC,p.id DESC LIMIT {$limit} OFFSET {$offset}");
        $q->execute($params); respond(['success'=>true,'data'=>$q->fetchAll(),'total'=>(int)$count->fetchColumn(),'pagina'=>$page,'limite'=>$limit]);
    }
    $data=requestJson();
    if ($method==='POST') {
        $name=adminText($data['nombre_proyecto']??null,150,'Nombre'); $contractor=adminText($data['contratista']??null,100,'Contratista'); $location=adminText($data['ubicacion']??null,255,'Ubicación');
        $template=proyectoPlantillaElegida($conn,$data['plantilla_id']??null);
        $conn->beginTransaction();
        $conn->prepare("INSERT INTO proyectos(nombre_proyecto,contratista,ubicacion,estado,creado_por,plantilla_id) VALUES(?,?,?,'activo',?,?)")->execute([$name,$contractor,$location,$user['id'],$template]);
        $id=(int)$conn->lastInsertId(); $after=adminFetch($conn,'proyectos',$id); adminAudit($conn,$user,'project_created','proyecto',['proyecto_id'=>$id],null,$after); $conn->commit();
        respond(['success'=>true,'message'=>'Proyecto creado.','data'=>$after],201);
    }
    if ($method==='PATCH') {
        $id=adminId($data['id']??null,'Proyecto'); $conn->beginTransaction(); $before=adminFetch($conn,'proyectos',$id,true);
        $action=(string)($data['accion']??'actualizar');
        if ($action==='actualizar') {
            $name=adminText($data['nombre_proyecto']??$before['nombre_proyecto'],150,'Nombre'); $contractor=adminText($data['contratista']??$before['contratista'],100,'Contratista'); $location=adminText($data['ubicacion']??$before['ubicacion'],255,'Ubicación');
            $template=array_key_exists('plantilla_id',$data)?proyectoPlantillaElegida($conn,$data['plantilla_id']):($before['plantilla_id']!==null?(int)$before['plantilla_id']:null);
            $previousTemplate=$before['plantilla_id']!==null?(int)$before['plantilla_id']:null;
            if ($template!==$previousTemplate && proyectoTieneInspecciones($conn,$id)) throw new InvalidArgumentException('Este proyecto ya tiene inspecciones registradas; su plantilla no se puede cambiar.');
            $conn->prepare('UPDATE proyectos SET nombre_proyecto=?,contratista=?,ubicacion=?,plantilla_id=? WHERE id=?')->execute([$name,$contractor,$location,$template,$id]); $event='project_updated';
        } elseif (in_array($action,['desactivar','reactivar'],true)) {
            $state=$action==='reactivar'?'activo':'cancelado'; $conn->prepare('UPDATE proyectos SET estado=? WHERE id=?')->execute([$state,$id]); $event=$action==='reactivar'?'project_enabled':'project_disabled';
        } else throw new InvalidArgumentException('Acción no válida.');
        $after=adminFetch($conn,'proyectos',$id); adminAudit($conn,$user,$event,'proyecto',['proyecto_id'=>$id],$before,$after); $conn->commit();
        respond(['success'=>true,'message'=>'Proyecto actualizado.','data'=>$after]);
    }
    respond(['success'=>false,'error'=>'Método no permitido.'],405);
} catch (InvalidArgumentException $e) { if($conn->inTransaction())$conn->rollBack(); respond(['success'=>false,'error'=>$e->getMessage()],422); }
catch (DomainException $e) { if($conn->inTransaction())$conn->rollBack(); respond(['success'=>false,'error'=>$e->getMessage()],404); }
catch (Throwable $e) { if($conn->inTransaction())$conn->rollBack(); appLog('administracion','Fallo en proyectos admin.',['motivo'=>$e->getMessage()]); respond(['success'=>false,'error'=>'No se pudo completar la operación. No se realizaron cambios.'],500); }
