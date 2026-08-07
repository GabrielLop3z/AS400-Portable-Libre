import sys
import json
from ftplib import FTP
import time
import os
import re
import io

# Determinar ruta base para logs (misma carpeta que el script o una arriba)
BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TRACE_FILE = os.path.join(BASE_DIR, 'trace.log')
DEBUG_RAW_FILE = os.path.join(BASE_DIR, 'debug_raw.txt')

def log_trace(msg):
    try:
        # Usar errors='replace' para evitar caídas por caracteres no-UTF8 del AS/400
        with open(TRACE_FILE, 'a', encoding='utf-8', errors='replace') as f:
            f.write(f'[{time.strftime("%H:%M:%S")}] {msg}\n')
    except: pass

def execute_as400(ftp, cmd):
    log_trace(f"Exec: {cmd}")
    try:
        # Forzar encoding para que ftplib no falle al decodificar respuestas con acentos/EBCDIC
        if hasattr(ftp, 'encoding'):
            ftp.encoding = 'latin-1'
        res = ftp.sendcmd(f"RCMD {cmd}")
        log_trace(f"  RCMD -> {res}")
        return True, res
    except Exception as e:
        # Capturamos el error tal cual venga
        err_msg = str(e)
        log_trace(f"  RCMD error: {err_msg}")
        return False, err_msg

def format_error(e):
    err_str = str(e)
    # [WinError 10060] Connection Timeout
    if "10060" in err_str or "timed out" in err_str.lower():
        return "ERROR DE RED: No se pudo conectar al Mainframe (Timeout). Verifique que esté conectado a la VPN o Red Corporativa."
    # [WinError 10061] Connection Refused
    if "10061" in err_str or "refused" in err_str.lower():
        return "ERROR DE ACCESO: El servidor AS/400 rechazó la conexión. Verifique que el servicio FTP esté activo en el host."
    # [WinError 10054] Connection Reset
    if "10054" in err_str:
        return "CONEXIÓN PERDIDA: El Mainframe cerró la conexión de forma inesperada."
    return f"ERROR DEL SISTEMA: {err_str}"

def list_spools(host, user, password, filter_user=None, limit=200, offset=0, filter_name=''):
    target = filter_user.upper() if filter_user else user.upper()
    log_trace(f"--- LISTADO IFS: Target={target}, Conn={user}, limit={limit}, offset={offset}, filter_name={filter_name} ---")
    
    try:
        ftp = FTP(host, timeout=15) # Reducimos un poco el timeout para feedback mas rapido
        ftp.login(user, password)
        ftp.sendcmd("SITE NAMEFMT 1")
        
        ts = int(time.time())
        tmp_file = "/tmp/spl_" + str(ts) + ".txt"
        
        qsh_cmd = "QSH CMD('system WRKSPLF " + target + " > " + tmp_file + "')"
        execute_as400(ftp, qsh_cmd)
        
        raw = bytearray()
        try:
            ftp.sendcmd("TYPE I")
            ftp.retrbinary("RETR " + tmp_file, raw.extend)
        except Exception as e:
            log_trace("Descarga falló: " + str(e))

        try: ftp.delete(tmp_file)
        except: pass
        ftp.quit()
        
        if len(raw) < 10: # WRKSPLF puede devolver archivos muy pequeños si no hay nada
            return {"success": False, "message": "No se encontraron spools para " + target}
            
        text = raw.decode('cp500', errors='replace')
        try:
            with open(DEBUG_RAW_FILE, 'w', encoding='utf-8') as f:
                f.write(text)
        except: pass
        lines = text.splitlines()
        
        # Detección dinámica de posiciones buscando la línea de encabezados
        idx_archivo, idx_usuario, idx_cola, idx_datos, idx_est, idx_total = 3, 14, 25, 36, 48, 54 # Defaults
        
        for l in lines:
            if 'Archivo' in l and 'Usuario' in l and 'Cola' in l:
                idx_archivo = l.find('Archivo')
                idx_usuario = l.find('Usuario')
                idx_cola = l.find('Cola')
                idx_datos = l.find('Datos Usu')
                idx_est = l.find('Est')
                idx_total = l.find('Total')
                break

        spools_all = []
        for line in lines:
            if len(line) < 50 or '5722' in line or 'Archivo' in line or 'Página' in line: continue
            
            try:
                # Extracción basada en índices dinámicos
                name = line[idx_archivo:idx_usuario].strip()
                user_f = line[idx_usuario:idx_cola].strip()
                cola = line[idx_cola:idx_datos].strip()
                datos_usu = line[idx_datos:idx_est].strip()
                status = line[idx_est:idx_total].strip()
                
                # Para el resto usamos regex ya que los campos numéricos varían más
                # pero intentamos capturar el bloque final de la línea
                m = re.search(r'(\d+)\s+([A-Z0-9$#@_]+)\s+(\d{6})', line[idx_total:])
                if m:
                    spl_num, job_name, job_num = m.groups()
                    job_id = job_num + "/" + user_f + "/" + job_name
                    spools_all.append({
                        "name": name, 
                        "user": user_f, 
                        "cola": cola, 
                        "datos_usu": datos_usu, 
                        "status": status, 
                        "pages": line[idx_total:idx_total+7].strip(), 
                        "job": job_id, 
                        "jobnbr": job_num,
                        "splnbr": spl_num
                    })
            except: pass
        
        # Aplicar filtro por nombre si viene
        if filter_name:
            fn = filter_name.upper()
            spools_all = [s for s in spools_all if fn in s.get('name','').upper() or fn in s.get('datos_usu','').upper()]
        
        total = len(spools_all)
        # Paginacion server-side
        spools_page = spools_all[offset:offset+limit]
            
        return {"success": True, "list": spools_page, "total": total, "offset": offset, "limit": limit}
            
    except Exception as e:
        return {"success": False, "message": format_error(e)}

def fetch_spool(host, user, password, file_name, job="*", number="*LAST"):
    log_trace(f"--- FETCH V5R3: File={file_name}, Job={job}, Num={number} ---")
    try:
        ftp = FTP(host, timeout=15)
        ftp.login(user, password)
        # Volver a NAMEFMT 0 para comandos de DB
        ftp.sendcmd("SITE NAMEFMT 0")
        
        t_pf = "S" + str(int(time.time() * 1000) % 100000)
        # Aumentamos RCDLEN a 1024 para soportar reportes muy anchos sin recortes
        execute_as400(ftp, f"CRTPF FILE(QTEMP/{t_pf}) RCDLEN(1024) SIZE(*NOMAX)")
        
        # Usar el JOB exacto y el numero exacto para V5R3. Añadimos CTLCHAR(*FCFC) para retener las sentencias de salto de pagina y negrita.
        cmd = "CPYSPLF FILE(" + file_name + ") TOFILE(QTEMP/" + t_pf + ") JOB(" + job + ") SPLNBR(" + number + ") CTLCHAR(*FCFC)"
        
        ok, res = execute_as400(ftp, cmd)
        
        raw = bytearray()
        if ok:
            ftp.sendcmd("TYPE I")
            try: ftp.retrbinary(f"RETR QTEMP/{t_pf}", raw.extend)
            except: pass
        
        execute_as400(ftp, f"DLTF FILE(QTEMP/{t_pf})")
        ftp.quit()
        
        if not raw:
            return {"success": False, "message": "ERROR: No se pudo recuperar el contenido. El archivo podria estar en HELD o bloqueado."}
            
        content = raw.decode('cp500', errors='replace')
        # Slicing dinámico basado en el nuevo RCDLEN (1024)
        lines = [content[i:i+1024].rstrip() for i in range(0, len(content), 1024)]
        return {"success": True, "data": lines if lines else ["Archivo vacio"]}
        
    except Exception as e:
        return {"success": False, "message": format_error(e)}

def manage_spool(host, user, password, sp_action, file_name, job, number, params=None):
    params = params or {}
    log_trace(f"--- MANAGE: action={sp_action} File={file_name} Job={job} Num={number} ---")
    try:
        ftp = FTP(host, timeout=15)
        ftp.login(user, password)
        ftp.sendcmd("SITE NAMEFMT 0")

        spec = "FILE(" + str(file_name) + ") JOB(" + str(job) + ") SPLNBR(" + str(number) + ")"

        if sp_action == "delete":
            cmd = "DLTSPLF " + spec
        elif sp_action == "hold":
            cmd = "HLDSPLF " + spec
        elif sp_action == "release":
            cmd = "RLSSPLF " + spec
        elif sp_action == "reprint":
            # Equivale a imprimir de nuevo: se libera para que el writer lo procese
            cmd = "CHGSPLFA " + spec + " STATUS(*READY)"
        elif sp_action == "change":
            opts = []
            if params.get("outq"):
                opts.append("OUTQ(" + str(params["outq"]) + ")")
            if params.get("forms"):
                opts.append("FORMS(" + str(params["forms"]) + ")")
            if params.get("copies") not in (None, ''):
                opts.append("COPIES(" + str(int(params["copies"])) + ")")
            if params.get("prty") not in (None, ''):
                opts.append("PRTY(" + str(int(params["prty"])) + ")")
            if params.get("usrdata"):
                opts.append("USRDTA(" + str(params["usrdata"]) + ")")
            if params.get("status"):
                opts.append("STATUS(" + str(params["status"]) + ")")
            if not opts:
                raise ValueError("Sin parámetros de cambio para CHGSPLFA")
            cmd = "CHGSPLFA " + spec + " " + " ".join(opts)
        else:
            raise ValueError("Acción desconocida: " + str(sp_action))

        ok, res = execute_as400(ftp, cmd)
        ftp.quit()

        if ok:
            return {"success": True, "message": "Comando ejecutado correctamente en el AS/400", "detail": res}
        return {"success": False, "message": res}
    except Exception as e:
        return {"success": False, "message": format_error(e)}


def get_user_info(host, user, password, target_user):
    log_trace(f"--- GET_USER_INFO: {target_user} ---")
    try:
        ftp = FTP(host, timeout=15)
        ftp.login(user, password)
        ftp.sendcmd("SITE NAMEFMT 1")
        
        tmp_file = "/tmp/uinfo_" + str(int(time.time())) + ".txt"
        # Comando para sacar el perfil a un archivo temporal
        cmd = "QSH CMD('system \"DSPUSRPRF USRPRF(" + target_user.upper() + ")\" > " + tmp_file + "')"
        execute_as400(ftp, cmd)
        
        raw = bytearray()
        try:
            ftp.sendcmd("TYPE I")
            ftp.retrbinary("RETR " + tmp_file, raw.extend)
        except: pass

        try: ftp.delete(tmp_file)
        except: pass
        ftp.quit()
        
        text = raw.decode('cp500', errors='replace')
        # Guardamos el volcado completo para que el usuario pueda elegir el campo exacto
        try:
            with open(os.path.join(BASE_DIR, 'user_debug.txt'), 'w', encoding='utf-8') as f:
                f.write(text)
        except: pass
        log_trace(f"FULL USER DATA CAPTURED: {len(text)} bytes")
        
        descr = target_user
        for line in text.splitlines():
            # Buscamos 'Texto' (ES) o 'Text' (EN)
            if "Texto" in line or "Text" in line:
                parts = line.split(":")
                if len(parts) > 1:
                    # Limpiamos puntos y espacios
                    val = parts[1].strip().replace('. . .', '').strip()
                    if val and val != target_user:
                        descr = val
                        break
        
        return {"success": True, "description": descr}
    except Exception as e:
        return {"success": False, "message": format_error(e)}

if __name__ == "__main__":
    if len(sys.argv) < 5: sys.exit(0)
    h, u, p, act = sys.argv[1:5]
    if act == "fetch":
        res = fetch_spool(h, u, p, sys.argv[5], sys.argv[6], sys.argv[7])
    elif act == "user_info":
        res = get_user_info(h, u, p, sys.argv[5])
    elif act == "manage":
        sp_action = sys.argv[5]
        file_name = sys.argv[6]
        job       = sys.argv[7]
        number    = sys.argv[8]
        params_raw = sys.argv[9] if len(sys.argv) > 9 else '{}'
        try:
            params = json.loads(params_raw) if params_raw else {}
        except Exception:
            params = {}
        res = manage_spool(h, u, p, sp_action, file_name, job, number, params)
    else:
        real_u = sys.argv[5] if len(sys.argv) > 5 else None
        lmt    = int(sys.argv[6]) if len(sys.argv) > 6 else 200
        off    = int(sys.argv[7]) if len(sys.argv) > 7 else 0
        fnm    = sys.argv[8] if len(sys.argv) > 8 else ''
        res = list_spools(h, u, p, real_u, lmt, off, fnm)
    print(json.dumps(res))
