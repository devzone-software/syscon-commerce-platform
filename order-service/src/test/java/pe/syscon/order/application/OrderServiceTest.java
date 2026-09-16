package pe.syscon.order.application;

import static org.junit.jupiter.api.Assertions.*;
import static org.mockito.Mockito.*;
import java.math.BigDecimal;
import java.util.*;
import org.junit.jupiter.api.Test;
import pe.syscon.common.*;
import pe.syscon.order.domain.SalesOrder;
import pe.syscon.order.infrastructure.OrderRepository;

class OrderServiceTest {
 @Test void rejectsTransitionFromUnpaidToShipped() {
  var repository=mock(OrderRepository.class);var catalog=mock(CatalogClient.class);
  var order=new SalesOrder(UUID.randomUUID());order.id=UUID.randomUUID();order.status="PAYMENT_PENDING";
  when(repository.findById(order.id)).thenReturn(Optional.of(order));
  var service=new OrderService(repository,catalog);
  assertThrows(ApiException.class,() -> service.transition(order.id,"SHIPPED"));
  verify(repository,never()).save(any());
 }
 @Test void calculatesOrderTotalFromCatalogPrices() {
  var repository=mock(OrderRepository.class);var catalog=mock(CatalogClient.class);
  UUID product=UUID.randomUUID(),customer=UUID.randomUUID();
  when(catalog.get(product)).thenReturn(ApiResponse.ok("Producto",new CatalogClient.Product(product,"SKU-1","Laptop",new BigDecimal("100.50"),3,null,null,null,true)));
  when(repository.save(any(SalesOrder.class))).thenAnswer(inv -> {SalesOrder o=inv.getArgument(0);o.id=UUID.randomUUID();return o;});
  var result=new OrderService(repository,catalog).create(customer,List.of(new OrderService.Line(product,2)));
  assertEquals(new BigDecimal("201.00"),result.total());assertEquals("PAYMENT_PENDING",result.status());
 }
}
