package pe.syscon.supplier;
import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;
import org.springframework.cloud.openfeign.EnableFeignClients;
import org.springframework.scheduling.annotation.EnableScheduling;
@SpringBootApplication(scanBasePackages="pe.syscon")
@EnableFeignClients
@EnableScheduling
public class SupplierApplication { public static void main(String[] args) { SpringApplication.run(SupplierApplication.class, args); } }
