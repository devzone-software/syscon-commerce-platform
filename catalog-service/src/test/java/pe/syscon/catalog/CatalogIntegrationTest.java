package pe.syscon.catalog;
import static org.junit.jupiter.api.Assertions.*;
import java.math.BigDecimal;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.test.context.DynamicPropertyRegistry;
import org.springframework.test.context.DynamicPropertySource;
import org.testcontainers.containers.PostgreSQLContainer;
import org.testcontainers.junit.jupiter.Container;
import org.testcontainers.junit.jupiter.Testcontainers;
import pe.syscon.catalog.application.CatalogService;
@SpringBootTest
@Testcontainers(disabledWithoutDocker=true)
class CatalogIntegrationTest {
 @Container static PostgreSQLContainer<?> postgres=new PostgreSQLContainer<>("postgres:17-alpine");
 @DynamicPropertySource static void properties(DynamicPropertyRegistry registry) {
  registry.add("spring.datasource.url",postgres::getJdbcUrl);
  registry.add("spring.datasource.username",postgres::getUsername);
  registry.add("spring.datasource.password",postgres::getPassword);
  registry.add("security.jwt.secret",() -> "integration-test-secret-of-at-least-32-bytes");
  registry.add("security.service-token",() -> "integration-test-internal-token-of-32-bytes");
 }
 @Autowired CatalogService catalog;
 @Test void upsertPreservesProductIdentityAndUpdatesStock() {
  var first=catalog.upsert(new CatalogService.ProductInput("SKU-TEST","Teclado",new BigDecimal("20.00"),5,null,"Periféricos","Marca",true));
  var updated=catalog.upsert(new CatalogService.ProductInput("SKU-TEST","Teclado",new BigDecimal("21.00"),3,null,"Periféricos","Marca",true));
  assertEquals(first.id(),updated.id());assertEquals(3,catalog.get(first.id()).stock());
 }
}
