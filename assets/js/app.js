document.addEventListener('DOMContentLoaded', () => {
  const currentYear = document.querySelector('[data-current-year]');
  if (currentYear) {
    currentYear.textContent = new Date().getFullYear();
  }

  const formOrden = document.querySelector('#formOrden');
  if (formOrden) {
    const btnCancelar = document.querySelector('#btnCancelarOrden');
    const btnActualizar = document.querySelector('#btnActualizarOrden');
    const btnGuardar = document.querySelector('#btnGuardarOrden');
    const inputId = document.querySelector('#orden_id');

    document.querySelectorAll('[data-orden-edit]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const data = btn.dataset;
        inputId.value = data.id || '';
        formOrden.querySelector('[name="oc"]').value = data.oc || '';
        formOrden.querySelector('[name="contrato"]').value = data.contrato || '';
        formOrden.querySelector('[name="fecha_entrega"]').value = data.fechaEntrega || '';
        formOrden.querySelector('[name="moneda_id"]').value = data.monedaId || '';
        formOrden.querySelector('[name="pep"]').value = data.pep || '';
        formOrden.querySelector('[name="sociedad"]').value = data.sociedad || 'CL13';
        formOrden.querySelector('[name="proyecto_id"]').value = data.proyectoId || '';
        formOrden.querySelector('[name="monto"]').value = data.monto || '';
        formOrden.querySelector('[name="monto_comprometido"]').value = data.montoComprometido || '';
        formOrden.querySelector('[name="estado"]').value = data.estado || 'Registrado';
        formOrden.querySelector('[name="hes"]').value = data.hes || '';
        formOrden.querySelector('[name="estado_detalle"]').value = data.estadoDetalle || 'Ingresado';
        formOrden.querySelector('[name="estado_detalle_otro"]').value = data.estadoDetalleOtro || '';
        formOrden.querySelector('[name="eliminada"]').checked = data.eliminada === '1';

        btnGuardar.classList.add('d-none');
        btnActualizar.classList.remove('d-none');
        btnCancelar.classList.remove('d-none');
      });
    });

    if (btnCancelar) {
      btnCancelar.addEventListener('click', () => {
        formOrden.reset();
        inputId.value = '';
        btnGuardar.classList.remove('d-none');
        btnActualizar.classList.add('d-none');
        btnCancelar.classList.add('d-none');
      });
    }
  }
});
