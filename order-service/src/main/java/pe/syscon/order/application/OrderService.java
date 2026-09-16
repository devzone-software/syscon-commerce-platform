package pe.syscon.order.application;
import java.math.BigDecimal;
import java.util.*;
import org.springframework.http.HttpStatus;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;
import pe.syscon.common.ApiException;
import pe.syscon.order.domain.*;
import pe.syscon.order.infrastructure.OrderRepository;
@Service public class OrderService {
 private final OrderRepository orders;private final CatalogClient catalog;
 public OrderService(OrderRepository orders,CatalogClient catalog) {this.orders=orders;this.catalog=catalog;}
 public record Line(UUID productId,int quantity) {}
 public record Detail(UUID productId,String sku,String name,int quantity,BigDecimal unitPrice) {}
 public record View(UUID id,UUID customerId,String status,BigDecimal total,List<Detail> details) {}
 @Transactional public View create(UUID customer,List<Line> lines) {
  if(lines==null||lines.isEmpty()) throw new ApiException(HttpStatus.BAD_REQUEST,"Pedido sin productos");
  SalesOrder order=new SalesOrder(customer);
  Set<UUID> seen=new HashSet<>();
  for(Line l:lines) {
   if(l.productId()==null||l.quantity()<1) throw new ApiException(HttpStatus.BAD_REQUEST,"Cantidad inválida");
   if(!seen.add(l.productId())) throw new ApiException(HttpStatus.BAD_REQUEST,"Producto duplicado en pedido");
   var response=catalog.get(l.productId());var p=response.data();
   if(p==null||!p.active()||p.stock()<l.quantity()) throw new ApiException(HttpStatus.CONFLICT,"Producto sin stock");
   order.details.add(new OrderDetail(order,p.id(),p.sku(),p.name(),l.quantity(),p.price()));
   order.total=order.total.add(p.price().multiply(BigDecimal.valueOf(l.quantity())));
  }
  order.status="PAYMENT_PENDING";return view(orders.save(order));
 }
 @Transactional(readOnly=true) public View get(UUID id) {return view(find(id));}
 @Transactional(readOnly=true) public List<View> list(UUID customer) {return orders.findByCustomerId(customer).stream().map(this::view).toList();}
 @Transactional public View markPaid(UUID id) {
  SalesOrder o=find(id);if("PAID".equals(o.status)) return view(o);
  if(!"PAYMENT_PENDING".equals(o.status)) throw new ApiException(HttpStatus.CONFLICT,"Estado de pedido incompatible");
  o.status="PAID";return view(orders.save(o));
 }
 @Transactional public View transition(UUID id,String target) {
  SalesOrder o=find(id);
  Map<String,String> next=Map.of("PAID","PROCESSING","PROCESSING","SHIPPED","SHIPPED","DELIVERED");
  if(!(target.equals(next.get(o.status))||target.equals("CANCELLED")&&List.of("CREATED","PAYMENT_PENDING").contains(o.status))) throw new ApiException(HttpStatus.CONFLICT,"Transición de pedido inválida");
  o.status=target;return view(orders.save(o));
 }
 private SalesOrder find(UUID id) {return orders.findById(id).orElseThrow(() -> new ApiException(HttpStatus.NOT_FOUND,"Pedido no encontrado"));}
 private View view(SalesOrder o) {return new View(o.id,o.customerId,o.status,o.total,o.details.stream().map(d -> new Detail(d.productId,d.sku,d.name,d.quantity,d.unitPrice)).toList());}
}
