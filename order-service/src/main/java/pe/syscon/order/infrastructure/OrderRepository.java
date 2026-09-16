package pe.syscon.order.infrastructure;
import java.util.*;
import org.springframework.data.jpa.repository.JpaRepository;
import pe.syscon.order.domain.SalesOrder;
public interface OrderRepository extends JpaRepository<SalesOrder,UUID> {List<SalesOrder> findByCustomerId(UUID customerId);}
