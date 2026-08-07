// main.js – Utilidades opcionales de enlace (Gatekeeper).
// Nota: El motor de temas (applyAppTheme, temas guardados) vive en index.php.
// Este archivo NO se incluye por defecto; es un overlay opcional para builds.
// No redefine applyAppTheme ni openGatekeeper para evitar colisiones.

// Aplica el tema guardado solo si index.php no lo hizo
document.addEventListener('DOMContentLoaded', () => {
    if (typeof applyAppTheme !== 'function') return;
    const saved = localStorage.getItem('app_theme') || 'negro';
    applyAppTheme(saved);
});

// Cambia la contraseña maestra del puente (process.php: update_gatekeeper)
function setMasterPassword() {
    Swal.fire({
        title: 'Configuración Maestra',
        html: `<input type="password" id="new-pass" class="swal2-input" placeholder="Nueva contraseña maestra">`,
        showCancelButton: true,
        confirmButtonText: 'Guardar',
        preConfirm: () => {
            const pwd = document.getElementById('new-pass').value;
            if (!pwd) Swal.showValidationMessage('La contraseña no puede estar vacía');
            return pwd;
        }
    }).then(result => {
        if (result.isConfirmed) {
            fetch('process.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'update_gatekeeper', password: result.value })
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) Swal.fire('¡Guardado!', 'Contraseña maestra actualizada.', 'success');
                    else Swal.fire('Error', data.message || 'Falló la actualización.', 'error');
                })
                .catch(() => Swal.fire('Error', 'Problema de red.', 'error'));
        }
    });
}

// Valida la contraseña maestra antes de acciones sensibles (process.php: check_gatekeeper / validate_gatekeeper)
function verifyGatekeeper(callback) {
    fetch('process.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'check_gatekeeper' })
    })
        .then(r => r.json())
        .then(data => {
            if (data.required) {
                Swal.fire({
                    title: 'Contraseña Maestra',
                    input: 'password',
                    inputPlaceholder: 'Ingrese la contraseña maestra',
                    showCancelButton: true,
                    confirmButtonText: 'Continuar'
                }).then(res => {
                    if (res.isConfirmed) {
                        fetch('process.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'validate_gatekeeper', password: res.value })
                        })
                            .then(r => r.json())
                            .then(v => {
                                if (v.valid) callback();
                                else Swal.fire('Incorrecto', 'Contraseña maestra inválida.', 'error');
                            });
                    }
                });
            } else {
                callback();
            }
        });
}
