package pe.syscon.payment.application;
import java.util.*;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.http.HttpStatus;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;
import pe.syscon.common.ApiException;
import pe.syscon.payment.domain.Payment;
import pe.syscon.payment.infrastructure.PaymentRepository;
@Service public class PaymentService {
 private final PaymentRepository payments;private final OrderClient orders;private final String token;
 public PaymentService(PaymentRepository payments,OrderClient orders,@Value("${security.service-token}") String token) {this.payments=payments;this.orders=orders;this.token=token;}
 @Transactional public Payment create(UUID orderId,UUID customerId,String provider) {
  if(!"MANUAL".equals(provider)) throw new ApiException(HttpStatus.BAD_REQUEST,"Proveedor aún no configurado");
  var o=orders.get(token,orderId).data();
  if(o==null||!o.customerId().equals(customerId)) throw new ApiException(HttpStatus.FORBIDDEN,"Pedido ajeno");
  if(!"PAYMENT_PENDING".equals(o.status())) throw new ApiException(HttpStatus.CONFLICT,"Pedido no pendiente");
  return payments.findByOrderIdAndProvider(orderId,provider).orElseGet(() -> payments.save(new Payment(orderId,customerId,provider,o.total())));
 }
 @Transactional(readOnly=true) public Payment get(UUID id,UUID customerId,boolean admin) {var p=find(id);if(!admin&&!p.customerId.equals(customerId)) throw new ApiException(HttpStatus.FORBIDDEN,"Pago ajeno");return p;}
 @Transactional public Payment confirm(UUID id,String reference) {
  var p=find(id);if("APPROVED".equals(p.status)) return p;
  if(!"PENDING".equals(p.status)) throw new ApiException(HttpStatus.CONFLICT,"Pago no pendiente");
  orders.paid(token,p.orderId);p.status="APPROVED";p.externalReference=reference;return payments.save(p);
 }
 private Payment find(UUID id) {return payments.findById(id).orElseThrow(() -> new ApiException(HttpStatus.NOT_FOUND,"Pago no encontrado"));}
}
